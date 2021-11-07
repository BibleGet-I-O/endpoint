<?php
/**
 * BibleGet I/O Project Service Endpoint
 * listens on both GET requests and POST requests
 * whether ajax or not
 * accepts all cross-domain requests
 * is CORS enabled ( as far as I understand it )
 * 
 * ENDPOINT URL:    https://query.bibleget.io/
 * 
 * AUTHOR:          John Romano D'Orazio
 * AUTHOR EMAIL:    priest@johnromanodorazio.com
 * AUTHOR WEBSITE:  https://www.johnromanodorazio.com
 * PROJECT WEBSITE: https://www.bibleget.io
 * PROJECT EMAIL:   admin@bibleget.io | bibleget.io@gmail.com
 * 
 * Copyright John Romano D'Orazio 2014-2021
 * Licensed under Apache License 2.0
 * 
 * This project is meant to contribute to the human community,
 * the community of mankind itself. 
 * Considering the Bible is among the oldest writings in the world,
 * and is the most read book of all human history
 * containing the wisdom of humanity from the most ancient times
 * and, for those who have faith, is the inspired Word of God himself
 * I deemed it necessary and useful to create a project
 * that would facilitate the usage of the Biblical texts
 * in the modern digital era.
 * 
 * My hope and desire is to be able to add 
 * as many different versions of the Bible in different languages
 * as possible, so that all men may have facilitated access to these texts
 * This project will always only utilize original source texts,
 * untouched by any third parties, so as to guarantee the authenticity of said texts.
 *    Deuteronomy 4:2
 *    "You shall not add to the word which I am commanding you, 
 *    nor take away from it, that you may keep the commandments 
 *    of the Lord your God which I command you."
 * 
 * I have no desire for any kind of economical advantage
 * over this project, nobody should speculate eonomically
 * over the wisdom of humanity or over the Word of God.
 * May it be of service to mankind. 
 * While I wish this endpoint engine to be open source,
 * available to men of good will who might desire to continue this project,
 * especially Biblical societies around the world,
 * and I hope the Pontifical Biblical Commission,
 * I cannot however offer the source texts and the databases they are held in
 * for public access, they are not all open source, 
 * they are often covered by copyright by Episcopal Conferences or by Biblical societies.
 * 
 * I wish for the code of this engine to be open source,
 * so that men of good will might contribute to making it better,
 * more secure, more reliable, to be of better service to mankind.
 * 
 * Blessed Carlo Acutis, pray for us
 * 
 * MINIMUM PHP REQUIREMENT: PHP 8.1 (allow for type declarations and mixed function return types)
 */

class BIBLEGET_QUOTE {

    static public array $returnTypes                = [ "json", "xml", "html" ];
    static public array $allowedAcceptHeaders       = [ "application/json", "application/xml", "text/html" ];
    static public array $allowedContentTypes        = [ "application/json", "application/x-www-form-urlencoded" ];
    static public array $allowedRequestMethods      = [ "GET", "POST" ];
    static public array $allowedPreferredOrigins    = [ "GREEK", "HEBREW" ];
    static public array $requestParameters          = [
      "query"         => "",
      "return"        => "",
      "version"       => "",
      "domain"        => "",
      "appid"         => "",
      "pluginversion" => "",
      "forceversion"  => "",
      "forcecopyright"=> "",
      "preferorigin"  => ""
    ];

    static public array $errorMessages = [
        0 => "The first query must start with a valid book indicator.",
        1 => "You must have a valid chapter following a book indicator.",
        2 => "The book indicator is not valid. Please check the documentation for a list of correct book indicators.",
        3 => "You cannot request discontinuous verses without first indicating a chapter for the discontinuous verses.",
        4 => "A request for discontinuous verses must contain two valid verse numbers on either side of a discontinuous verse indicator.",
        5 => "A chapter-verse separator must be preceded by a valid chapter number and followed by a valid verse number.",
        6 => "A request for a range of verses must contain two valid verse numbers on either side of a verse range indicator.",
        7 => "If there is a chapter-verse construct following a dash, there must also be a chapter-verse construct preceding the same dash.",
        8 => "Multiple verse ranges have been requested, but there are not enough verse separators. Multiple verse ranges assume there are verse separators that connect them.",
        9 => "Notation Error. Please check your citation notation.",
        10 => "Please use a cacheing mechanism, you seem to be submitting numerous requests for the same query.",
        11 => "You are submitting too many requests with the same query. You must use a cacheing mechanism. Once you have implemented a cacheing mechanism you may have to wait a couple of days before getting service again. Otherwise contact the service management to request service again.",
        12 => "You are submitting a very large amount of requests to the endpoint. Please slow down. If you believe there has been an error you may contact the service management."
    ];


    public string $jsonEncodedRequestHeaders   = "";
    public string $originHeader                = "";
    public string $requestMethod               = "";
    public string $responseContentType         = "json";     //response Content-type ( json, xml or html )
    public array $WhitelistedDomainsIPs        = [];
    public array $requestHeaders               = [];
    public string $acceptHeader                = "";
    public string $requestContentType          = "";
    //public bool $isAjax                        = false;
    public string $detectedNotation            = "ENGLISH"; //can be "ENGLISH" or "EUROPEAN" or "MIXED" (first two are valid, last value is invalid)

    public mysqli $mysqli;                     //instance of database
    public stdClass|SimpleXMLElement|DOMDocument $bibleQuote; //object with json, xml or html data to return
    //useful for html output:
    public DOMElement $div;
    public DOMElement $err;
    public DOMElement $inf;

    public array $queries                       = [];
    public array $validatedQueries              = [];
    public array $validatedVariants             = [];
    public array $formulatedQueries             = [];
    public array $formulatedVariants            = [];
    public array $originalQueries               = [];
    public array $VALID_VERSIONS                = [];
    public array $VALID_VERSIONS_FULLNAME       = [];
    public array $COPYRIGHT_VERSIONS            = [];
    public array $PROTESTANT_VERSIONS           = [];
    public array $CATHOLIC_VERSIONS             = [];
    public array $REQUESTED_VERSIONS            = [];
    public array $REQUESTED_COPYRIGHTED_VERSIONS = [];
    public array $BIBLEBOOKS                    = [];
    public array $INDEXES                       = [];
    public array $DATA                          = []; //all request parameters
    public bool $DEBUG_REQUESTS                 = false;
    public bool $DEBUG_IPINFO                   = false;
    public string $DEBUGFILE                    = "requests.log";

    function __construct( array $DATA ){
        $this->requestHeaders = getallheaders();
        $this->jsonEncodedRequestHeaders = json_encode( $this->requestHeaders );
        $this->originHeader = key_exists( "ORIGIN", $this->requestHeaders ) ? $this->requestHeaders["ORIGIN"] : "";
        $this->requestContentType = isset( $_SERVER['CONTENT_TYPE'] ) && in_array( $_SERVER['CONTENT_TYPE'], self::$allowedContentTypes ) ? $_SERVER['CONTENT_TYPE'] : "";
        $this->acceptHeader = isset( $this->requestHeaders["Accept"] ) && in_array( $this->requestHeaders["Accept"], self::$allowedAcceptHeaders ) ? ( string ) $this->requestHeaders["Accept"] : "";
        $this->requestMethod = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : $_SERVER["REQUEST_METHOD"];
        $this->responseContentType = ( isset( $DATA["return"] ) && in_array( strtolower( $DATA["return"] ),self::$returnTypes ) ) ? strtolower( $DATA["return"] ) : ( $this->acceptHeader !== "" ? ( string ) self::$returnTypes[array_search( $this->requestHeaders["Accept"], self::$allowedAcceptHeaders )] : ( string ) self::$returnTypes[0] );
        //$this->isAjax = ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' );
        //let's ensure that we have at least default values for parameters
        $this->DATA = array_merge( self::$requestParameters, $DATA );
        $this->DATA["preferorigin"] = in_array( $this->DATA["preferorigin"], self::$allowedPreferredOrigins ) ? $this->DATA["preferorigin"] : "";
    }


    static public function stringWithUpperAndLowerCaseVariants( string $str ) : bool {
        return preg_match( "/\p{L&}/u", $str );
    }

    static private function toProperCase( string $txt ) : string {
        if( self::stringWithUpperAndLowerCaseVariants( $txt ) === false ){
            return $txt;
        } else {
            preg_match( "/\p{L&}/u", $txt, $mList, PREG_OFFSET_CAPTURE );
            if( $mList && array_key_exists( 0, $mList ) ){
                $offset = $mList[0][1];
                $chr = mb_substr( $txt, $offset, 1, 'UTF-8' );
                $post = mb_substr( $txt, $offset+1, null, 'UTF-8' );
                return mb_substr( $txt, 0, $offset, 'UTF-8' ) . mb_strtoupper( $chr, 'UTF-8' ) . mb_strtolower( $post, 'UTF-8' );
            } else {
                return $txt;
            }
        }
    }

    static public function idxOf( string $needle, array $haystack ) : int|bool {
        foreach ( $haystack as $index => $value ) {
            if ( is_array( $haystack[$index] ) ) {
                foreach ( $haystack[$index] as $index2 => $value2 ) {
                    if ( in_array( $needle, $haystack[$index][$index2] ) ) {
                        return $index;
                    }
                }
            } else if ( in_array( $needle, $haystack[$index] ) ) {
                return $index;
            }
        }
        return false;
    }


    static private function normalizeBibleBook( string $str ) : string {
        return self::toProperCase( preg_replace( "/\s+/", "", trim( $str ) ) );
    }

    static private function detectAndNormalizeNotation( string &$querystr ) : string {
        $detectedNotation = "";

        //if query is written in english notation, convert it to european notation
        $find = [ ".", ",", ":" ];
        $replace = [ "", ".", "," ];

        if ( strpos( $querystr, ":" ) && strpos( $querystr, "." ) ) {
            //can't use both notations
            $detectedNotation = "MIXED";
        } else if ( strpos( $querystr, ":" ) && strpos( $querystr, "," ) && strpos( $querystr, ";" ) ) {
            //check if the comma is used invalidly, this will only happen if there is more than one query
            //this is why we check if there is a semicolon
            //let's split all queries into an array
            $queries = explode( ";", $querystr );
            //let's remove any book and chapter indicators from the beginning of each query
            $queries = preg_replace( "/^([1-3]{0,1}((\p{Lu}\p{Ll}*)*))([1-9][0-9]{0,2})/u", "", $queries );
            //let's get the first character that was following any chapter indicators
            $queries = array_map(function($v) { return substr($v, 0, 1); }, $queries);
            if( in_array( ":", $queries ) && in_array( ",", $queries ) ){
                $detectedNotation = "MIXED";
            } else if ( in_array( ":", $queries ) ){
                $detectedNotation = "ENGLISH";
                $querystr = str_replace( $find, $replace, $querystr );
            } else {
                $detectedNotation = "EUROPEAN";
            }
        } else if ( strpos( $querystr, ":" ) ) {
            $detectedNotation = "ENGLISH";
            $querystr = str_replace( $find, $replace, $querystr );
        } else {
            $detectedNotation = "EUROPEAN";
        }

        return $detectedNotation;
    }

    static private function removeWhitespace( string $querystr ) : string {
        $querystr = preg_replace( '/\s+/', '', $querystr );
        return str_replace( ' ', '', $querystr );
    }

    static private function convertAllDashesToHyphens( string $querystr ) : string {
        return preg_replace( '/[\x{2011}-\x{2015}|\x{2212}|\x{23AF}]/u', '-', $querystr );
    }

    static private function removeEmptyItems( array $queries ) : array {
        return array_values( array_filter( $queries, function ( $var ) {
            return $var !== "";
        } ) );
    }

    private function isValidVersion( string $version ) : bool {
        return( in_array( $version, $this->VALID_VERSIONS ) );
    }

    private function queryStrClean() {

        $querystr = self::removeWhitespace( $this->DATA["query"] );
        $querystr = trim( $querystr );
        $querystr = self::convertAllDashesToHyphens( $querystr );
        $this->detectedNotation = self::detectAndNormalizeNotation( $querystr );

        //if there are multiple queries separated by semicolons, we explode them into an array
        $queries = explode( ";", $querystr );
        $queries = self::removeEmptyItems( $queries );
        $queries = array_map( 'self::toProperCase', $queries );
        $this->queries = $queries;

    }


    public function addErrorMessage( $num, $str="" ) {

        if ( gettype( $num ) === "string" ) {
            self::$errorMessages[13] = $num;
            $num = 13;
        }

        if ( $this->responseContentType === "json" ) {
            $error = [];
            $error["errNum"] = $num;
            $error["errMessage"] = self::$errorMessages[$num] . ( $str !== "" ? " > " . $str : "" );
            $this->bibleQuote->errors[] = $error;
        } elseif ( $this->responseContentType === "xml" ) {
            $err_row = $this->bibleQuote->Errors->addChild( "error", self::$errorMessages[$num] );
            $err_row->addAttribute( "errNum", $num );
        } elseif ( $this->responseContentType === "html" ) {
            $elements = [];
            $errorsTable = $this->bibleQuote->getElementById( "errorsTbl" );
            if ( $errorsTable == null ) {
                $elements[0] = $this->bibleQuote->createElement( "table" );
                $elements[0]->setAttribute( "id","errorsTbl" );
                $elements[0]->setAttribute( "class","errorsTbl" );
                $this->err->appendChild( $elements[0] );
            } else {
                $elements[0] = $errorsTable;
            }

            $elements[1] = $this->bibleQuote->createElement( "tr" );
            $elements[1]->setAttribute( "id","errorsRow" );
            $elements[1]->setAttribute( "class","errorsRow" );
            $elements[0]->appendChild( $elements[1] );
            
            $elements[2] = $this->bibleQuote->createElement( "td", "errNum" );
            $elements[2]->setAttribute( "class", "errNum" );
            $elements[1]->appendChild( $elements[2] );

            $elements[3] = $this->bibleQuote->createElement( "td", $num );
            $elements[3]->setAttribute( "class", "errNumVal" );
            $elements[1]->appendChild( $elements[3] );
        
            $elements[4] = $this->bibleQuote->createElement( "td", "errMessage" );
            $elements[4]->setAttribute( "class", "errMessage" );
            $elements[1]->appendChild( $elements[4] );

            $elements[5] = $this->bibleQuote->createElement( "td", self::$errorMessages[$num] );
            $elements[5]->setAttribute( "class", "errMessageVal" );
            $elements[1]->appendChild( $elements[5] );

        }
    }

    private function outputResult() {

        switch( $this->responseContentType ) {
            case "json":
                $this->bibleQuote->info["detectedNotation"] = $this->detectedNotation;
                echo json_encode( $this->bibleQuote, JSON_UNESCAPED_UNICODE );
                break;
            case "xml":
                $this->bibleQuote->info["detectedNotation"] = $this->detectedNotation;
                echo $this->bibleQuote->asXML();
                break;
            case "html":
                $this->bibleQuote->appendChild( $this->div );
                $this->bibleQuote->appendChild( $this->err ); 
                $info = $this->bibleQuote->createElement( "input" );
                $info->setAttribute( "type", "hidden" );
                $info->setAttribute( "name", "ENDPOINT_VERSION" );
                $info->setAttribute( "value", ENDPOINT_VERSION );
                $info->setAttribute( "class", "BibleGetInfo" );
                $this->inf->appendChild( $info );
                $info1 = $this->bibleQuote->createElement( "input" );
                $info1->setAttribute( "type", "hidden" );
                $info1->setAttribute( "name", "detectedNotation" );
                $info1->setAttribute( "value", $this->detectedNotation );
                $info1->setAttribute( "class", "BibleGetInfo" );
                $this->inf->appendChild( $info1 );
                $this->bibleQuote->appendChild( $this->inf );
                echo $this->bibleQuote->saveHTML( $this->div );
                echo $this->bibleQuote->saveHTML( $this->err );
                echo $this->bibleQuote->saveHTML( $this->inf );
        }

        if ( $this->mysqli && $this->mysqli->thread_id ) {
            $this->mysqli->close();
        }
        exit( 0 );

    }

    private function dbConnect() {

        $dbCredentials = "dbcredentials.php";
        //search for the database credentials file at least three levels up...
        if( file_exists( $dbCredentials ) ){
            include_once( $dbCredentials );
        } else if ( file_exists( "../" . $dbCredentials ) ){
            include_once( "../{$dbCredentials}" );
        } else if ( file_exists( "../../" . $dbCredentials ) ){
            include_once( "../../{$dbCredentials}" );
        }

        $mysqli = new mysqli( SERVER,DBUSER,DBPASS,DATABASE );

        if ( $mysqli->connect_errno ) {
            $this->addErrorMessage( "Failed to connect to MySQL: ( " . $mysqli->connect_errno . " ) " . $mysqli->connect_error );
            $this->outputResult();
        }
        $mysqli->set_charset( "utf8" );
        $this->mysqli = $mysqli;
        if( defined('WHITELISTED_DOMAINS_IPS') ){
            $this->WhitelistedDomainsIPs = WHITELISTED_DOMAINS_IPS;
        }
    }

    private function BibleQuoteInit() {

        switch( $this->responseContentType ){
            case "json":
                $quote = new stdClass();
                $quote->results = [];
                $quote->errors = [];
                $quote->info = ["ENDPOINT_VERSION" => ENDPOINT_VERSION];
                break;
            case "xml":
                $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?"."><BibleQuote/>";
                $quote = new simpleXMLElement( $root );
                $errors = $quote->addChild( "errors" );
                $info = $quote->addChild( "info" );
                $results = $quote->addChild( "results" );
                $info->addAttribute( "ENDPOINT_VERSION", ENDPOINT_VERSION );
                break;
            case "html":
                $quote = new DOMDocument();
                $html = "<!DOCTYPE HTML><head><title>BibleGet Query Result</title><style>table#errorsTbl { border: 3px double Red; background-color:DarkGray; } table#errorsTbl td { border: 1px solid Black; background-color:LightGray; padding: 3px; } td.errNum,td.errMessage { font-weight:bold; }</style><!-- QUERY.BIBLEGET.IO ENDPOINT VERSION {ENDPOINT_VERSION} --></head><body></body>";
                $quote->loadHTML( $html );
                $div = $quote->createElement( "div" );
                $div->setAttribute( "class","results bibleQuote" );
                $err = $quote->createElement( "div" );
                $err->setAttribute( "class","errors bibleQuote" );
                $inf = $quote->createElement( "div" );
                $inf->setAttribute( "class", "info bibleQuote" );
                $this->div        = $div;
                $this->err        = $err;
                $this->inf        = $inf;
                break;
        }

        $this->bibleQuote = $quote;

    }

    private function populateVersionsInfo(){

        $result = $this->mysqli->query( "SELECT * FROM versions_available WHERE type = 'BIBLE'" );
        if( $result ) {
            while( $row = mysqli_fetch_assoc( $result ) ) {
                $this->VALID_VERSIONS[] = $row["sigla"];
                $this->VALID_VERSIONS_FULLNAME[$row["sigla"]] = $row["fullname"] . "|" . $row["year"];
                if ( $row["copyright"] === 1 ) {
                    $this->COPYRIGHT_VERSIONS[] = $row["sigla"];
                }
                if( $row["canon"] === "CATHOLIC" ){
                    $this->CATHOLIC_VERSIONS[] = $row["sigla"];
                } else if ( $row["canon"] === "PROTESTANT" ){
                    $this->PROTESTANT_VERSIONS[] = $row["sigla"];
                }
            }
        }
        else{
            $this->addErrorMessage( "<p>MySQL ERROR ".$this->mysqli->errno . ": " . $this->mysqli->error."</p>" );
            $this->outputResult();
        }

    }

    private function prepareIndexes(){

        $indexes = [];

        foreach( $this->REQUESTED_VERSIONS as $variant ){

            $abbreviations  = [];
            $bbbooks        = [];
            $chapter_limit  = [];
            $verse_limit    = [];
            $book_num       = [];

            // fetch the index information for the requested version from the database and load it into our arrays
            $result = $this->mysqli->query( "SELECT * FROM ".$variant."_idx" );
            if( $result ){
                while( $row = $result->fetch_assoc() ){
                    $abbreviations[]    = $row["abbrev"];
                    $bbbooks[]          = $row["fullname"];
                    $chapter_limit[]    = $row["chapters"];
                    $verse_limit[]      = explode( ",", $row["verses_last"] );
                    $book_num[]         = $row["book"];
                }
            }

            $indexes[$variant]["abbreviations"]   = $abbreviations;
            $indexes[$variant]["biblebooks"]      = $bbbooks;
            $indexes[$variant]["chapter_limit"]   = $chapter_limit;
            $indexes[$variant]["verse_limit"]     = $verse_limit;
            $indexes[$variant]["book_num"]        = $book_num;  

        }

        $this->INDEXES = $indexes;

    }

    private function prepareBibleBooks() {

        $result1 = $this->mysqli->query( "SELECT * FROM biblebooks_fullname" );
        if ( $result1 ) {
            $cols = mysqli_num_fields( $result1 );
            $names = [];
            $finfo = mysqli_fetch_fields( $result1 );
            foreach ( $finfo as $val ) {
                $names[] = $val->name;
            }
            $result2 = $this->mysqli->query( "SELECT * FROM biblebooks_abbr" );
            if ( $result2 ) {
                $cols2 = mysqli_num_fields( $result2 );
                $rows2 = mysqli_num_rows( $result2 );
                $names2 = [];
                $finfo2 = mysqli_fetch_fields( $result2 );
                foreach ( $finfo2 as $val ) {
                    $names2[] = $val->name;
                }

                $n = 0;
                while ( $row1 = mysqli_fetch_assoc( $result1 ) ) {
                    $row2 = mysqli_fetch_assoc( $result2 );
                    $this->BIBLEBOOKS[$n] = [];

                    for ( $x = 1; $x < $cols; $x++ ) {
                        $temparray = [ $row1[$names[$x]], $row2[$names[$x]] ];

                        $arr1 = explode( " | ", $row1[$names[$x]] );
                        $booknames = array_map( 'self::normalizeBibleBook', $arr1 );

                        $arr2 = explode( " | ", $row2[$names[$x]] );
                        $abbrevs = ( count( $arr2 ) > 1 ) ? array_map( 'self::normalizeBibleBook', $arr2 ) : [];

                        $this->BIBLEBOOKS[$n][$x] = array_merge( $temparray, $booknames, $abbrevs );
                    }
                    $n++;
                }
            } else {
                $this->addErrorMessage( "<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>" );
            }
        } else {
            $this->addErrorMessage( "<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>" );
        }

    }

    private function prepareRequestedVersions() {

        $temp = isset( $this->DATA["version"] ) && $this->DATA["version"] !== "" ? explode( ",", strtoupper( $this->DATA["version"] ) ) : ["CEI2008"];

        foreach ( $temp as $version ) {
            if ( isset( $this->DATA["forceversion"] ) && $this->DATA["forceversion"] === "true" ) {
                $this->REQUESTED_VERSIONS[] = $version;
            } else {
                if ( $this->isValidVersion( $version ) ) {
                    $this->REQUESTED_VERSIONS[] = $version;
                } else {
                    $this->addErrorMessage( "Not a valid version: <" . $version . ">, valid versions are <" . implode( " | ", $this->VALID_VERSIONS ) . ">" );
                }
            }
            if ( isset( $this->DATA["forcecopyright"] ) && $this->DATA["forcecopyright"] === "true" ) {
                $this->REQUESTED_COPYRIGHTED_VERSIONS[] = $version;
            }
        }


        if ( count( $this->REQUESTED_VERSIONS ) < 1 ) {
            $this->outputResult();
        }

    }

    public function incrementBadQueryCount() {
        $this->mysqli->query( "UPDATE counter SET bad = bad + 1" );
    }

    public function incrementGoodQueryCount() {
        $this->mysqli->query( "UPDATE counter SET good = good + 1" );
    }

    private function writeEntryToDebugFile( string $entry ) {
        file_put_contents( $this->DEBUGFILE, date( 'r' ) . "\t" . $entry . PHP_EOL, FILE_APPEND | LOCK_EX );
    }


    public function Init() {

        switch( $this->responseContentType ){
            case "xml":
              header( 'Content-Type: application/xml; charset=utf-8' );
              break;
            case "json":
              header( 'Content-Type: application/json; charset=utf-8' );
              break;
            case "html":
              header( 'Content-Type: text/html; charset=utf-8' );
              break;
            default:
              header( 'Content-Type: application/json; charset=utf-8' );
        }

        $this->BibleQuoteInit();
        $this->dbConnect();
        $this->populateVersionsInfo();
        $this->prepareBibleBooks();
        $this->prepareRequestedVersions();
        $this->prepareIndexes();

        if ( isset( $this->DATA["query"] ) && $this->DATA["query"] !== "" ) {

            $this->queryStrClean();

            if( $this->detectedNotation === "MIXED" ){

                $this->addErrorMessage( "Mixed notations have been detected, please use either english or european notation." );
                $this->outputResult();

            }

            $QUERY_VALIDATOR = new QUERY_VALIDATOR( $this );

            if( $QUERY_VALIDATOR->ValidateQueries() === false ){

                $this->outputResult();

            } else {

                if ( !is_array( $this->validatedVariants ) ) {

                    $this->outputResult();

                } else {

                    // 3 -> TRANSLATE BIBLE NOTATION QUERIES TO MYSQL QUERIES
                    $SQL_QUERY_FORMULATOR = new QUERY_FORMULATOR( $this );
                    $SQL_QUERY_FORMULATOR->FormulateSQLQueries();
    
                    // 5 -> DO MYSQL QUERIES AND COLLECT RESULTS IN OBJECT 
                    $SQL_QUERY_EXECUTOR = new QUERY_EXECUTOR( $this );
                    $SQL_QUERY_EXECUTOR->ExecuteSQLQueries();
                    //$this->doQueries();
    
                    // 6 -> OUTPUT RESULTS FORMATTED ACCORDING TO REQUESTED RETURN TYPE
                    $this->outputResult();

                }

            }

        }

    }

}
