<?php
declare(strict_types=1);

$Crawler = new HelpCrawler();
$Crawler->LoadLinks();
$Crawler->DiscoverFromSearch();
$Crawler->Crawl();
$Crawler->SaveLinks();

class HelpCrawler
{
	/** @var \CurlHandle */
	private $CurlHandle;

	/** @var array<string, ?array> */
	private array $Articles = [];

	/** @var \SplQueue<string> */
	private \SplQueue $LinksQueue;
	private string $Directory = __DIR__ . '/docs/';
	private string $LinksFile = __DIR__ . '/articles.json';
	private bool $IsCI;

	public function __construct()
	{
		$this->IsCI = \getenv( 'CI' ) !== false;
		$this->LinksQueue = new \SplQueue();

		$this->CurlHandle = curl_init( );

		curl_setopt_array( $this->CurlHandle, [
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SteamHelpTracking; +https://github.com/SteamDatabase/SteamHelpTracking)',
			CURLOPT_ENCODING       => 'gzip',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT        => 30,
		] );
	}

	public function LoadLinks() : void
	{
		if( !file_exists( $this->LinksFile ) )
		{
			throw new Exception( 'File does not exist: ' . $this->LinksFile );
		}

		$Data = file_get_contents( $this->LinksFile );

		if( $Data === false )
		{
			throw new Exception( 'Failed to read ' . $this->LinksFile );
		}

		$Links = json_decode( $Data, true );

		foreach( $Links as $FaqID => $FAQ )
		{
			if( isset( $this->Articles[ $FaqID ] ) )
			{
				continue;
			}

			$this->Articles[ $FaqID ] = $FAQ;
			$this->LinksQueue->push( (string)$FaqID );
		}

		echo 'Loaded ' . count( $Links ) . ' known articles' . PHP_EOL;
	}

	public function SaveLinks() : void
	{
		ksort( $this->Articles, SORT_NUMERIC );

		file_put_contents( $this->LinksFile, json_encode( $this->Articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );

		echo 'Saved ' . count( $this->Articles ) . ' articles' . PHP_EOL;
	}

	public function Crawl() : void
	{
		while( !$this->LinksQueue->isEmpty() )
		{
			$Link = $this->LinksQueue->pop();
			$Content = $this->Fetch( $Link );

			if( $Content !== null )
			{
				$this->Process( $Content );
			}
		}
	}

	public function DiscoverFromSearch() : void
	{
		$Queries =
		[
			'Steam',
			'Valve',
			'I',
			'http',
			'https',
		];

		if( $this->IsCI )
		{
			echo "::group::DiscoverFromSearch" . PHP_EOL;
		}

		foreach( $Queries as $Query )
		{
			$this->DiscoverFromSearchQuery( $Query );
		}

		if( $this->IsCI )
		{
			echo "::endgroup::" . PHP_EOL;
		}
	}

	private function DiscoverFromSearchQuery( string $Query ) : void
	{
		$Cursor = '';

		do
		{
			echo 'Searching "' . $Query . '" cursor ' . $Cursor . '... ';

			curl_setopt( $this->CurlHandle, CURLOPT_URL, 'https://api.steampowered.com/IClanFAQSService/SearchFAQs/v1/?' . http_build_query( [
				'count' => 100,
				'search_text' => $Query,
				'cursor' => $Cursor,
			] ) );

			$Content = (string)curl_exec( $this->CurlHandle );

			echo curl_getinfo( $this->CurlHandle, CURLINFO_HTTP_CODE ) . ' - ';

			$Data = json_decode( $Content, true );

			echo ( $Data[ 'response' ][ 'num_total_results' ] ?? 0 ) . ' results - ';
			echo count( $this->Articles ) . ' total articles' . PHP_EOL;

			$Cursor = $Data[ 'response' ][ 'next_cursor' ] ?? '';

			if( empty( $Data[ 'response' ][ 'faqs' ] ) )
			{
				break;
			}

			foreach( $Data[ 'response' ][ 'faqs' ] as $FAQ )
			{
				if( isset( $this->Articles[ $FAQ[ 'articleid' ] ] ) )
				{
					continue;
				}

				$this->Articles[ $FAQ[ 'articleid' ] ] = null;
				$this->LinksQueue->push( $FAQ[ 'articleid' ] );
			}
		}
		while( !empty( $Cursor ) && $Cursor !== '*' );
	}

	private function Fetch( string $FaqID ) : ?array
	{
		echo 'Fetching ' . $FaqID . '... ';

		curl_setopt( $this->CurlHandle, CURLOPT_URL, 'https://api.steampowered.com/IClanFAQSService/GetFAQ/v1/?' . http_build_query( [
			'faq_id' => $FaqID,
			'language' => 0,
		] ) );

		$Content = (string)curl_exec( $this->CurlHandle );

		echo curl_getinfo( $this->CurlHandle, CURLINFO_HTTP_CODE ) . ' - ';

		$Data = json_decode( $Content, true );

		if( empty( $Data[ 'response' ][ 'faq' ] ) )
		{
			echo '[NOT FOUND]' . PHP_EOL;
			return null;
		}

		echo $Data[ 'response' ][ 'faq' ][ 'title' ] . PHP_EOL;

		return $Data[ 'response' ][ 'faq' ];
	}

	private function Process( array $FAQ ) : void
	{
		$this->Articles[ $FAQ[ 'faq_id' ] ] =
		[
			'title' => $FAQ[ 'title' ],
			'url_code' => $FAQ[ 'url_code' ],
			'version' => (int)$FAQ[ 'version' ],
			'timestamp' => date( 'Y-m-d H:i:s', $FAQ[ 'timestamp' ] ),
		];

		$CleanContent = str_replace( [
			"\r",
			"[section",
			"[/section]",
			"[expand",
			"[/expand]",
			"[exclude_realm",
			"[/exclude_realm]",
			"[list]",
			"[/list]",
			"[olist]",
			"[/olist]",
			"[table]",
			"[tr]",
			"[/tr]",
			"[*]",
			"[/*]",
			"[hr]",
			"[/hr]",
			"[h2]",
			"[/h2]",
			"[h3]",
			"[/h3]",
			"[h4]",
			"[/h4]",
			"[h5]",
			"[/h5]",
		],
		[
			"",
			"\n\n[section",
			"\n[/section]\n\n",
			"\n\n[expand",
			"\n[/expand]\n\n",
			"\n[exclude_realm",
			"\n[/exclude_realm]\n",
			"\n\n[list]\n",
			"\n[/list]\n",
			"\n\n[olist]\n",
			"\n[/olist]\n",
			"\n\n[table]\n",
			"\n[tr]\n",
			"\n[/tr]\n",
			"\n[*]",
			" [/*]\n",
			"\n[hr]",
			"[/hr]\n",
			"\n\n[h2]",
			"[/h2]\n",
			"\n\n[h3]",
			"[/h3]\n",
			"\n\n[h4]",
			"[/h4]\n",
			"\n\n[h5]",
			"[/h5]\n",
		], $FAQ[ 'content' ] );

		$CleanContent = preg_replace( [
			'/^[\t ]+|[\t ]+$/m',
			'/\[\/\*]\n{2,}\[\*]/',
			'/\n{2,}/',
		], [
			"",
			"[/*]\n[*]",
			"\n\n",
		], $CleanContent );

		$Content = "[h1]{$FAQ[ 'title' ]}[/h1]\n\n";
		$Content .= trim( $CleanContent );
		$Content .= "\n";

		$FileName = substr( $FAQ[ 'url_code' ] . '-' . $this->TitleToSlug( $FAQ[ 'title' ] ), 0, 150 );
		$FullPath = str_replace( '\\', '/', $this->Directory . $FileName . '.txt' );

		// Delete old files if any
		$FilesToDelete = $this->Directory . $FAQ[ 'url_code' ] . '-*.txt';

		foreach( glob( $FilesToDelete ) as $FileToDelete )
		{
			if( str_replace( '\\', '/', $FileToDelete ) === $FullPath )
			{
				continue;
			}

			$this->Err( 'Deleting ' . $FileToDelete );

			unlink( $FileToDelete );
		}

		file_put_contents( $FullPath, $Content );
	}

	/** @pure */
	private function TitleToSlug( string $Title ) : string
	{
		$Title = strtolower( $Title );
		$Title = preg_replace( '/[^\pL\d]+/u', '-', $Title ); // replace non letter or digits by dashes
		$Title = preg_replace( '/[^a-z0-9\-]/', '', $Title ); // remove unwanted characters
		$Title = trim( $Title, '-' ); // trim dashes

		return $Title;
	}

	private function Err( string $Message ) : void
	{
		if( $this->IsCI )
		{
			echo "::error::" . $Message . PHP_EOL;
		}
		else
		{
			fwrite( STDERR, $Message . PHP_EOL );
		}
	}
}
