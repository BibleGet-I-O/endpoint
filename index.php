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
 * MINIMUM PHP REQUIREMENT: PHP 7.4 (allow for type declarations)
 */

ini_set( 'display_errors', 1 );
ini_set( 'display_startup_errors', 1 );
error_reporting( E_ALL );

define( "ENDPOINT_VERSION", "3.0" );
define( "BIBLEGETIOQUERYSCRIPT", "iknowwhythisishere" );

// Don't allow bots to access this script!
if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && preg_match( '/bot|crawl|slurp|spider/i', $_SERVER['HTTP_USER_AGENT'] ) ) {
  exit( 0 );
}

/*************************************************************
 * SET HEADERS TO ALLOW ANY KIND OF REQUESTS FROM ANY ORIGIN * 
 * AND CONTENT-TYPE BASED ON REQUESTED RETURN TYPE           *
 ************************************************************/

// Allow from any origin
if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
    header( "Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}" );
    header( 'Access-Control-Allow-Credentials: true' );
    header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
}
// Access-Control headers are received during OPTIONS requests
if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
    if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ) )
        header( "Access-Control-Allow-Methods: GET, POST" );
    if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ) )
        header( "Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}" );
}


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

    const QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR                            = 0;
    const VALID_CHAPTER_MUST_FOLLOW_BOOK                                        = 1;
    const IS_VALID_BOOK_INDICATOR                                               = 2;
    const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR           = 3;
    const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS                     = 4;
    const CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS             = 5;
    const VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS                          = 6;
    const CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS   = 7;
    const CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES              = 8;

    /*
    static public $sections = [
        "Pentateuco",
        "Storici",
        "Sapienziali",
        "Profeti",
        "Vangeli",
        "Atti degli Apostoli",
        "Lettere Paoline",
        "Lettere Cattoliche",
        "Apocalisse"
    ];
    */

    private array $DATA                         = []; //all request parameters
    private string $returnType                  = "json";     //which type of data to return ( json, xml or html )
    private array $requestHeaders               = [];
    private string $acceptHeader                = "";
    private string $requestMethod               = "";
    private string $contentType                 = "";
    private string $originHeader                = "";
    private bool $isAjax                        = false;
    private $bibleQuote;                        //object with json, xml or html data to return (stdClass|SimpleXMLElement|DOMDocument)
    private mysqli $mysqli;                     //instance of database
    private array $WhitelistedDomainsIPs        = [];
    private array $validversions                = [];
    private array $validversions_fullname       = [];
    private array $copyrightversions            = [];
    private array $PROTESTANT_VERSIONS          = [];
    private array $CATHOLIC_VERSIONS            = [];
    private string $detectedNotation            = "ENGLISH"; //can be "ENGLISH" or "EUROPEAN"
    private array $biblebooks                   = [];
    private array $requestedVersions            = [];
    private array $requestedCopyrightedVersions = [];
    private array $indexes                      = [];
    private string $geoip_json                  = "";
    private bool $haveIPAddressOnRecord         = false;
    private string $jsonEncodedRequestHeaders   = "";
    private string $curYEAR                     = "";
    //useful for html output:
    private DOMElement $div;
    private DOMElement $err;
    private DOMElement $inf;
    public bool $DEBUG_REQUESTS                 = false;
    public bool $DEBUG_IPINFO                   = false;
    public string $DEBUGFILE                    = "requests.log";

    function __construct( array $DATA ){
        $this->requestHeaders = getallheaders();
        $this->jsonEncodedRequestHeaders = json_encode( $this->requestHeaders );
        $this->originHeader = key_exists( "ORIGIN", $this->requestHeaders ) ? $this->requestHeaders["ORIGIN"] : "";
        $this->contentType = isset( $_SERVER['CONTENT_TYPE'] ) && in_array( $_SERVER['CONTENT_TYPE'], self::$allowedContentTypes ) ? $_SERVER['CONTENT_TYPE'] : "";
        $this->acceptHeader = isset( $this->requestHeaders["Accept"] ) && in_array( $this->requestHeaders["Accept"], self::$allowedAcceptHeaders ) ? ( string ) $this->requestHeaders["Accept"] : "";
        $this->requestMethod = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : $_SERVER["REQUEST_METHOD"];
        $this->returnType = ( isset( $DATA["return"] ) && in_array( strtolower( $DATA["return"] ),self::$returnTypes ) ) ? strtolower( $DATA["return"] ) : ( $this->acceptHeader !== "" ? ( string ) self::$returnTypes[array_search( $this->requestHeaders["Accept"], self::$allowedAcceptHeaders )] : ( string ) self::$returnTypes[0] );
        $this->isAjax = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] );
        //let's ensure that we have at least default values for parameters
        $this->DATA = array_merge( self::$requestParameters, $DATA );
        $this->DATA["preferorigin"] = in_array( $this->DATA["preferorigin"], self::$allowedPreferredOrigins ) ? $this->DATA["preferorigin"] : "";
        $this->curYEAR = date( 'Y' );
    }


    static private function stringWithUpperAndLowerCaseVariants( string $str ) : bool {
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

    static private function idxOf( string $needle, array $haystack ) {
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


    static private function normalizeBibleBook( string $str ){
        return self::toProperCase( preg_replace( "/\s+/", "", trim( $str ) ) );
    }

    static private function forceArray( $element ) : array {
        if ( !is_array( $element ) ) {
            $element = [ $element ];
        }
        return $element;
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

    static private function matchBookInQuery( string $query ) {
        if( self::stringWithUpperAndLowerCaseVariants( $query ) ){
            if( preg_match( "/^([1-3]{0,1}((\p{Lu}\p{Ll}*)+))/u", $query, $res ) ){
                return $res;
            } else {
                return false;
            }
        } else {
            if( preg_match( "/^([1-3]{0,1}((\p{L}\p{M}*)+))/u", $query, $res ) ){
                return $res;
            } else {
                return false;
            }
        }
    }

    static private function validateRuleAgainstQuery( int $rule, string $query ) : bool {
        $validation = false;
        switch( $rule ){
            case self::QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR :
                $validation = (preg_match( "/^[1-3]{0,1}\p{Lu}\p{Ll}*/u", $query ) || preg_match( "/^[1-3]{0,1}(\p{L}\p{M}*)+/u", $query ));
                break;
            case self::VALID_CHAPTER_MUST_FOLLOW_BOOK :
                if(self::stringWithUpperAndLowerCaseVariants( $query ) ){
                    $validation = ( preg_match( "/^[1-3]{0,1}\p{Lu}\p{Ll}*/u", $query ) == preg_match( "/^[1-3]{0,1}\p{Lu}\p{Ll}*[1-9][0-9]{0,2}/u", $query ) );
                } else {
                    $validation = ( preg_match( "/^[1-3]{0,1}( \p{L}\p{M}* )+/u", $query ) == preg_match( "/^[1-3]{0,1}(\p{L}\p{M}*)+[1-9][0-9]{0,2}/u", $query ) );
                }
                break;
            /*case self::IS_VALID_BOOK_INDICATOR :
                $validation = (1===1);
                break;*/
            case self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR :
                $validation = !( !strpos( $query, "," ) || strpos( $query, "," ) > strpos( $query, "." ) );
                break;
            case self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS :
                $validation = ( preg_match_all( "/(?<![0-9])(?=([1-9][0-9]{0,2}\.[1-9][0-9]{0,2}))/", $query ) === substr_count( $query, "." ) );
                break;
            case self::CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS :
                $validation = ( preg_match_all( "/[1-9][0-9]{0,2}\,[1-9][0-9]{0,2}/", $query ) === substr_count( $query, "," ) );
                break;
            case self::VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS :
                $validation = ( preg_match_all( "/[1-9][0-9]{0,2}\-[1-9][0-9]{0,2}/", $query ) === substr_count( $query, "-" ) );
                break;
            case self::CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS :
                $validation = !( preg_match( "/\-[1-9][0-9]{0,2}\,/", $query ) && ( !preg_match( "/\,[1-9][0-9]{0,2}\-/", $query ) || preg_match_all( "/(?=\,[1-9][0-9]{0,2}\-)/", $query ) > preg_match_all( "/(?=\-[1-9][0-9]{0,2}\,)/", $query ) ) );
                break;
            case self::CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES :
                $validation = !( substr_count( $query, "-" ) > 1 && ( !strpos( $query, "." ) || ( substr_count( $query, "-" ) - 1 > substr_count( $query, "." ) ) ) );
                break;
        }
        return $validation;
    }

    static private function queryContainsDiscountinuousVerses( string $query ) : bool {
        return strpos( $query, "." ) !== false;
    }

    static private function queryContainsChapterVerseSeparator( string $query ) : bool {
        return strpos( $query, "," ) !== false;
    }

    static private function getAllChapterIndicators( string $query ) : array {
        if( preg_match_all( "/([1-9][0-9]{0,2})\,/", $query, $chapterIndicators ) ){
            $chapterIndicators[1] = self::forceArray( $chapterIndicators[1] );
            return $chapterIndicators;
        } else {
            return ["",[]];
        }
    }

    static private function getVerseAfterChapterVerseSeparator( string $query ) : array {
        if( preg_match( "/,([1-9][0-9]{0,2})/", $query, $verse ) ){
            return $verse;
        } else {
            return [[],[]];
        }
    }

    static private function getAllVersesAfterDiscontinuousVerseIndicator( string $query ) : array {
        if( preg_match_all( "/\.([1-9][0-9]{0,2})$/", $query, $discontinuousVerses ) ){
            $discontinuousVerses[1] = self::forceArray( $discontinuousVerses[1] );
            return $discontinuousVerses;
        } else {
            return [[],[]];
        }
    }


    private function addErrorMessage( $num, $str="" ) {

        if ( gettype( $num ) === "string" ) {
            self::$errorMessages[13] = $num;
            $num = 13;
        }

        if ( $this->returnType === "json" ) {
            $error = [];
            $error["errNum"] = $num;
            $error["errMessage"] = self::$errorMessages[$num] . ( $str !== "" ? " > " . $str : "" );
            $this->bibleQuote->errors[] = $error;
        } elseif ( $this->returnType === "xml" ) {
            $err_row = $this->bibleQuote->Errors->addChild( "error", self::$errorMessages[$num] );
            $err_row->addAttribute( "errNum", $num );
        } elseif ( $this->returnType === "html" ) {
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

        switch( $this->returnType ) {
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

        switch( $this->returnType ){
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
                $this->validversions[] = $row["sigla"];
                $this->validversions_fullname[$row["sigla"]] = $row["fullname"] . "|" . $row["year"];
                if ( $row["copyright"] === 1 ) {
                    $this->copyrightversions[] = $row["sigla"];
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

    private function isValidVersion( string $version ) : bool {
        return( in_array( $version, $this->validversions ) );
    }

    private function prepareIndexes(){

        $indexes = [];

        foreach( $this->requestedVersions as $variant ){

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

        $this->indexes = $indexes;

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
                    $this->biblebooks[$n] = [];

                    for ( $x = 1; $x < $cols; $x++ ) {
                        $temparray = [ $row1[$names[$x]], $row2[$names[$x]] ];

                        $arr1 = explode( " | ", $row1[$names[$x]] );
                        $booknames = array_map( 'self::normalizeBibleBook', $arr1 );

                        $arr2 = explode( " | ", $row2[$names[$x]] );
                        $abbrevs = ( count( $arr2 ) > 1 ) ? array_map( 'self::normalizeBibleBook', $arr2 ) : [];

                        $this->biblebooks[$n][$x] = array_merge( $temparray, $booknames, $abbrevs );
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
                $this->requestedVersions[] = $version;
            } else {
                if ( $this->isValidVersion( $version ) ) {
                    $this->requestedVersions[] = $version;
                } else {
                    $this->addErrorMessage( "Not a valid version: <" . $version . ">, valid versions are <" . implode( " | ", $this->validversions ) . ">" );
                }
            }
            if ( isset( $this->DATA["forcecopyright"] ) && $this->DATA["forcecopyright"] === "true" ) {
                $this->requestedCopyrightedVersions[] = $version;
            }
        }


        if ( count( $this->requestedVersions ) < 1 ) {
            $this->outputResult();
        }

    }

    private function queryStrClean() : array {

        $querystr = self::removeWhitespace( $this->DATA["query"] );
        $querystr = trim( $querystr );
        $querystr = self::convertAllDashesToHyphens( $querystr );
        $this->detectedNotation = self::detectAndNormalizeNotation( $querystr );

        //if there are multiple queries separated by semicolons, we explode them into an array
        $queries = explode( ";", $querystr );
        $queries = self::removeEmptyItems( $queries );
        $queries = array_map( 'self::toProperCase', $queries );
        return $queries;

    }

    private function queryViolatesAnyRuleOf( string $query, array $rules ) : bool {
        foreach( $rules as $rule ) {
            if( self::validateRuleAgainstQuery( $rule, $query ) === false ){
                $this->addErrorMessage( $rule );
                $this->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    private function incrementBadQueryCount() {
        $this->mysqli->query( "UPDATE counter SET bad = bad + 1" );
    }

    private function incrementGoodQueryCount() {
        $this->mysqli->query( "UPDATE counter SET good = good + 1" );
    }

    private function isValidBookForVariant( string $currentBook, string $variant ) : bool {
        return ( in_array( $currentBook, $this->indexes[$variant]["biblebooks"] ) || in_array( $currentBook, $this->indexes[$variant]["abbreviations"] ) );
    }

    private function validateBibleBook( &$validatedQueries ) : bool {
        $bookIsValid = false;
        foreach ( $this->requestedVersions as $variant ) {
            if ( $this->isValidBookForVariant( $validatedQueries->currentBook, $variant ) ) {
                $bookIsValid = true;
                $validatedQueries->currentVariant = $variant;
                $validatedQueries->bookIdxBase = self::idxOf( $validatedQueries->currentBook, $this->biblebooks );
                break;
            }
        }
        if( !$bookIsValid ) {
            $validatedQueries->bookIdxBase = self::idxOf( $validatedQueries->currentBook, $this->biblebooks );
            if( $validatedQueries->bookIdxBase !== false){
                $bookIsValid = true;
            } else {
                $this->addErrorMessage( sprintf( 'The book %s is not a valid Bible book. Please check the documentation for a list of correct Bible book names, whether full or abbreviated.', $validatedQueries->currentBook ) );
                $this->incrementBadQueryCount();
            }
        }
        $validatedQueries->nonZeroBookIdx = $validatedQueries->bookIdxBase + 1;
        return $bookIsValid;
    }

    private function validateChapterIndicators( array $chapterIndicators, object $validatedQueries ) : bool {

        foreach ( $chapterIndicators[1] as $chapterIndicator ) {
            foreach ( $this->indexes as $jkey => $jindex ) {
                $bookidx = array_search( $validatedQueries->nonZeroBookIdx, $jindex["book_num"] );
                $chapter_limit = $jindex["chapter_limit"][$bookidx];
                if ( $chapterIndicator > $chapter_limit ) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$s>, and <%4$d> must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->addErrorMessage( sprintf( $msg, $chapterIndicator, $validatedQueries->currentBook, $jkey, $chapter_limit ) );
                    $this->incrementBadQueryCount();
                    return false;
                }
            }
        }

        return true;

    }

    private function validateMultipleVerseSeparators( object $validatedQueries ) : bool {
        if ( !strpos( $validatedQueries->currentQuery, '-' ) ) {
            $this->addErrorMessage( "You cannot have more than one comma and not have a dash!" );
            $this->incrementBadQueryCount();
            return false;
        }
        $parts = explode( "-", $validatedQueries->currentQuery );
        if ( count( $parts ) != 2 ) {
            $this->addErrorMessage( "You seem to have a malformed querystring, there should be only one dash." );
            $this->incrementBadQueryCount();
            return false;
        }
        foreach ( $parts as $part ) {
            $pp = array_map( "intval", explode( ",", $part ) );
            foreach ( $this->indexes as $jkey => $jindex ) {
                $bookidx = array_search( $validatedQueries->nonZeroBookIdx, $jindex["book_num"] );
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval( $chapters_verselimit[$pp[0] - 1] );
                if ( $pp[1] > $verselimit ) {
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->addErrorMessage( sprintf( $msg, $pp[1], $validatedQueries->currentBook, $pp[0], $jkey, $verselimit ) );
                    $this->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    private function validateRightHandSideOfVerseSeparator( object $validatedQueries, array $parts ) : bool {
        if ( preg_match_all( "/[,\.][1-9][0-9]{0,2}\-([1-9][0-9]{0,2})/", $validatedQueries->currentQuery, $matches ) ) {
            $matches[1] = self::forceArray( $matches[1] );
            $highverse = intval( array_pop( $matches[1] ) );

            foreach ( $this->indexes as $jkey => $jindex ) {
                $bookidx = array_search( $validatedQueries->nonZeroBookIdx, $jindex["book_num"] );
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );

                if ( $highverse > $verselimit ) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->addErrorMessage( sprintf( $msg, $highverse, $validatedQueries->currentBook, $parts[0], $jkey, $verselimit ) );
                    $this->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    private function validateVersesAfterChapterVerseSeparators( object $validatedQueries, array $parts ) : bool {
        $versesAfterChapterVerseSeparators = self::getVerseAfterChapterVerseSeparator( $validatedQueries->currentQuery );

        $highverse = intval( $versesAfterChapterVerseSeparators[1] );
        foreach ( $this->indexes as $jkey => $jindex ) {
            $bookidx = array_search( $validatedQueries->nonZeroBookIdx, $jindex["book_num"] );
            $chapters_verselimit = $jindex["verse_limit"][$bookidx];
            $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );
            if ( $highverse > $verselimit ) {
                /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->addErrorMessage( sprintf( $msg, $highverse, $validatedQueries->currentBook, $parts[0], $jkey, $verselimit ) );
                $this->incrementBadQueryCount();
                return false;
            }
        }
        return true;
    }

    private function highVerseOutOfBounds( $highverse, object $validatedQueries, array $parts ) : bool {
        foreach ( $this->indexes as $jkey => $jindex ) {
            $bookidx = array_search( $validatedQueries->nonZeroBookIdx, $jindex["book_num"] );
            $chapters_verselimit = $jindex["verse_limit"][$bookidx];
            $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );
            if ( $highverse > $verselimit ) {
                /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->addErrorMessage( sprintf( $msg, $highverse, $validatedQueries->currentBook, $parts[0], $jkey, $verselimit ) );
                $this->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    private function chapterOutOfBounds( array $chapters, object $validatedQueries ) : bool {
        foreach ( $chapters as $zchapter ) {
            foreach ( $this->indexes as $jkey => $jindex ) {
                
                $bookidx = array_search( $validatedQueries->nonZeroBookIdx, $jindex["book_num"] );
                $chapter_limit = $jindex["chapter_limit"][$bookidx];
                if ( intval( $zchapter ) > $chapter_limit ) {
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->addErrorMessage( sprintf( $msg, $zchapter, $validatedQueries->currentBook, $jkey, $chapter_limit ) );
                    $this->incrementBadQueryCount();
                    return true;
                }
            }
        }
        return false;
    }

    private function validateQueries( $queries ) {

        $validatedQueries                   = new stdClass();
        $validatedQueries->usedvariants     = [];
        $validatedQueries->goodqueries      = [];
        $validatedQueries->currentVariant   = "";
        $validatedQueries->currentBook      = "";
        $validatedQueries->bookIdxBase      = -1;
        $validatedQueries->nonZeroBookIdx   = -1;
        $validatedQueries->currentQuery     = "";
        $validatedQueries->currentFullQuery = "";

        foreach ( $queries as $query ) {
            $validatedQueries->currentFullQuery = $query;
            $validatedQueries->currentQuery = $query;

            if( $this->queryViolatesAnyRuleOf( $validatedQueries->currentQuery, [ self::VALID_CHAPTER_MUST_FOLLOW_BOOK ] ) ){
                return false;
            }

            $matchedBook = self::matchBookInQuery( $validatedQueries->currentQuery );
            if ( $matchedBook !== false ) {
                $validatedQueries->currentBook = $matchedBook[0];
                if ( $this->validateBibleBook( $validatedQueries ) === false ) {
                    continue;
                } else {
                    $validatedQueries->currentQuery = str_replace( $validatedQueries->currentBook, "", $validatedQueries->currentQuery );
                }
            }

            if ( self::queryContainsDiscountinuousVerses( $validatedQueries->currentQuery ) ) {
                $rules = [ 
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR,
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS
                ];
                if ( $this->queryViolatesAnyRuleOf( $validatedQueries->currentQuery, $rules ) ) {
                    continue;
                }
            }

            if ( self::queryContainsChapterVerseSeparator( $validatedQueries->currentQuery ) ) {
                if ( $this->queryViolatesAnyRuleOf( $validatedQueries->currentQuery, [ self::CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS ] ) ) {
                    continue;
                } else {
                    $chapterIndicators = self::getAllChapterIndicators( $validatedQueries->currentQuery );
                    if( $this->validateChapterIndicators( $chapterIndicators, $validatedQueries ) === false ){
                        continue;
                    }

                    $chapterVerseSeparatorCount = substr_count( $validatedQueries->currentQuery, "," );
                    if ( $chapterVerseSeparatorCount > 1 ) {
                        if( $this->validateMultipleVerseSeparators( $validatedQueries ) === false ){
                            continue;
                        }
                    } elseif ( $chapterVerseSeparatorCount == 1 ) {
                        $parts = explode( ",", $validatedQueries->currentQuery );
                        if ( strpos( $parts[1], '-' ) ) {
                            if( $this->validateRightHandSideOfVerseSeparator( $validatedQueries, $parts ) === false ) {
                                continue;
                            }
                        } else {
                            if( $this->validateVersesAfterChapterVerseSeparators( $validatedQueries, $parts ) === false ){
                                continue;
                            }
                        }

                        $discontinuousVerses = self::getAllVersesAfterDiscontinuousVerseIndicator( $validatedQueries->currentQuery );
                        $highverse = array_pop( $discontinuousVerses[1] );
                        if( $this->highVerseOutOfBounds( $highverse, $validatedQueries, $parts ) ) {
                            continue;
                        }
                    }

                }
            } else {
                $chapters = explode( "-", $validatedQueries->currentQuery );
                if( $this->chapterOutOfBounds( $chapters, $validatedQueries ) ){
                    continue;
                }
            }

            if ( strpos( $validatedQueries->currentQuery, "-" ) ) {
                $rules = [
                    self::VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS,
                    self::CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS,
                    self::CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES
                ];
                if ( $this->queryViolatesAnyRuleOf( $validatedQueries->currentQuery, $rules ) ) {
                    continue;
                }
            }
            $validatedQueries->usedvariants[] = $validatedQueries->currentVariant;
            $validatedQueries->goodqueries[]  = $validatedQueries->currentFullQuery;

        } //END FOREACH

        return $validatedQueries;
    }

    static private function captureBookIndicator( string &$currentQuery, bool $hasULVariants ) {
        if( $hasULVariants ){
            if( preg_match( "/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", $currentQuery, $ret ) ){
                $currentQuery = preg_replace( "/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", "", $currentQuery );
                return $ret;
            } else {
                return false;
            }
        } else {
            if( preg_match( "/^[1-4]{0,1}\p{L}+/u", $currentQuery, $ret ) ){
                $currentQuery = preg_replace( "/^[1-4]{0,1}\p{L}+/u", "", $currentQuery );
                return $ret;
            } else {
                return false;
            }
        }
    }

    private function bestGuessBookIdx( array $matchedBook, object $formulatedQueries ) {
        $key1 = $formulatedQueries->currentVariant != "" ? array_search( $matchedBook[0], $this->indexes[$formulatedQueries->currentVariant]["biblebooks"] ) : false;
        $key2 = $formulatedQueries->currentVariant != "" ? array_search( $matchedBook[0], $this->indexes[$formulatedQueries->currentVariant]["abbreviations"] ) : false;
        $key3 = self::idxOf( $matchedBook[0], $this->biblebooks );
        if ( $key1 ) {
            return $this->indexes[$formulatedQueries->currentVariant]["book_num"][$key1];
        } else if ( $key2 ) {
            return $this->indexes[$formulatedQueries->currentVariant]["book_num"][$key2];
        } else if ( $key3 ) {
            return $key3 + 1;
        }
    }

    private function formulateSQLQueries( object $validatedQueries ) {

        $formulatedQueries = new stdClass();
        $formulatedQueries->queries             = $validatedQueries->goodqueries;
        $formulatedQueries->currentQuery        = "";
        $formulatedQueries->currentFullQuery    = "";
        $formulatedQueries->usedvariants        = $validatedQueries->usedvariants;
        $formulatedQueries->sqlqueries          = [];
        $formulatedQueries->queriesversions     = [];
        $formulatedQueries->originalquery       = [];
        $formulatedQueries->nn                  = 0;
        $formulatedQueries->i                   = -1;
        $formulatedQueries->sqlquery            = "";
        $formulatedQueries->previousBook        = "";
        $formulatedQueries->currentBook         = "";
        $formulatedQueries->currentVariant      = "";

        foreach ( $this->requestedVersions as $version ) {
            $formulatedQueries->i = 0;
            foreach ( $formulatedQueries->queries as $query ) {
                $formulatedQueries->currentQuery = $query;
                $formulatedQueries->currentFullQuery = $query;

                // Retrieve and store the book in the query string,if applicable
                $hasULVariants = self::stringWithUpperAndLowerCaseVariants( $formulatedQueries->currentQuery );
                $matchedBook = self::captureBookIndicator( $formulatedQueries->currentQuery, $hasULVariants );
                if ( $matchedBook ) {
                    $formulatedQueries->currentVariant = $formulatedQueries->usedvariants[$formulatedQueries->i];
                    $formulatedQueries->currentBook = $this->bestGuessBookIdx( $matchedBook, $formulatedQueries );
                    $formulatedQueries->previousBook = $formulatedQueries->currentBook;
                } else {
                    $formulatedQueries->currentBook = $formulatedQueries->previousBook;
                }

                $formulatedQueries->sqlquery = "SELECT * FROM " . $version . " WHERE book = " . $formulatedQueries->currentBook;

                $preferorigin = "";
                //if we are dealing with a book that has greek and hebrew variants, we need to distinguish between the two
                if( $formulatedQueries->currentBook == 19 || $formulatedQueries->currentBook == "19" ){ 
                    //if a protestant version is requested, it will only have HEBREW origin, not GREEK
                    //if preferorigin is not explicitly set, but chapters 11-20 are requested for Esther,
                    //then obviously the HEBREW version is being preferred, we will need to translate this request when we know which chapter we are dealing with
                    if( in_array( $version,$this->CATHOLIC_VERSIONS ) ){
                        $preferorigin = " AND verseorigin = '" . ( $this->DATA['preferorigin'] != "" ? $this->DATA['preferorigin'] : "GREEK" ) . "'";
                    }
                }

                $xchapter = "";
                //NOTE: all notations have been translated to EUROPEAN notation for the following calculations
                //      1 ) Therefore we will not find colons, but commas for the chapter-verse separator
                //      2 ) We will not find commas for non consecutive verses, but dots
                //NOTE: since a dash can have multiple meanings, whereas a dot has one meaning, 
                //      also considering that the dot has to do with the smallest unit in a Bible reference = verse,
                //      whereas a dash could have to do either with verses or with chapters
                //      we start our interpretation of the Bible reference from what is clear and certain
                //      and is the smallest possible unit that we have to work with.
                //      So if there is a dot = non-consecutive verses, we start splitting up the reference around the dot ( or dots )
                //NOTE: We have already capture the book, so we are not dealing with the book in the following calculations
                //      However for our own sanity, the book is included in the examples to help understand what is happening
                //      This symbol will be used to indicate splitting into left hand and right hand sections: <=|=>

                //IF: non-consecutive verses are requested ( EXAMPLE: John 3,16.18 )
                if ( strpos( $formulatedQueries->currentQuery, "." ) ) {
                    $querysplit = preg_split( "/\./", $formulatedQueries->currentQuery );
                    //FOREACH non-consecutive chunk requested ( John 3,16 <=|=> 18 )
                    foreach ( $querysplit as $piece ) {
                        $formulatedQueries->originalquery[$formulatedQueries->nn] = $formulatedQueries->currentFullQuery;
                        //IF the chunk is not simply a single verse, but is a range of consecutive VERSES or CHAPTERS 
                        //    ( EXAMPLE: John 3,16-18.20       OR John 3,16.18-20        OR John 3,16-18.20-22 )
                        //    (         John 3,16-18 <=|=> 20 OR John 3,16 <=|=> 18-20  OR John 3,16-18 <=|=> 20-22 )
                        if ( strpos( $piece, "-" ) ) {
                            $fromto = preg_split( "/\-/", $piece );
                            //IF we have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  ( EXAMPLE: John 3,16-18.20 )
                            //  ( John 3,16-18 <=|=> 20 )
                            //  ( John 3 <=> 16-18 )
                            if ( strpos( $fromto[0], "," ) ) {
                                //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                $chapterverse = preg_split( "/,/", $fromto[0] );
                                $xchapter = $chapterverse[0];
                                $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$chapterverse[0],$chapterverse[1],$preferorigin );
                                $chapterverse[0] = $mappedReference[0];
                                $chapterverse[1] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                //IF we have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                //  ( EXAMPLE: John 3,16-4,5.8 )
                                //  ( John 3,16 <=|=> 4,5 )
                                if ( strpos( $fromto[1], "," ) ) {
                                    //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                    $chapterverse1 = preg_split( "/,/", $fromto[1] );
                                    $xchapter = $chapterverse1[0];
                                    $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$chapterverse1[0],$chapterverse1[1],$preferorigin );
                                    $chapterverse1[0] = $mappedReference[0];
                                    $chapterverse1[1] = $mappedReference[1];
                                    $preferorigin = $mappedReference[2];
                                    $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND ( ( chapter = " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . " )";
                                    if( $chapterverse1[0] - $chapterverse[0] > 1 ) {
                                        for( $d=1;$d<( $chapterverse1[0] - $chapterverse[0] );$d++ ){
                                            $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " OR ( chapter = " . ( $chapterverse[0] + $d ) . " )";
                                        }
                                    }
                                    $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " OR ( chapter = " . $chapterverse1[0] . " AND verse <= " . $chapterverse1[1] . " ) )";
                                }
                                //ELSEIF we do NOT have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                // ( EXAMPLE: John 3,16-18.20 )
                                else {
                                    $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$xchapter,$fromto[1],$preferorigin );
                                    $xchapter = $mappedReference[0];
                                    $fromto[1] = $mappedReference[1];
                                    $preferorigin = $mappedReference[2];
                                    $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND ( chapter >= " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . " )";
                                    $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " AND ( chapter <= " . $xchapter . " AND verse <= " . $fromto[1] . " )";
                                }
                            }
                            //ELSEIF we DO NOT have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  ( EXAMPLE: John 3,16.18-20 )
                            //  (  John 3,16 <=|=> 18-20 )
                            //  (  18 <=> 20 )
                            else {
                                $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$xchapter,$fromto[0],$preferorigin );
                                $mappedReference1 = $this->mapReference( $version,$formulatedQueries->currentBook,$xchapter,$fromto[1],$preferorigin );
                                $xchapter = $mappedReference[0];
                                $fromto[0] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                $fromto[1] = $mappedReference1[1];
                                $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND ( chapter = " . $xchapter . " AND verse >= " . $fromto[0] . " AND verse <= " . $fromto[1] . " )";
                            }
                        }
                        //ELSEIF the non consecutive chunk DOES NOT contain a range of consecutive VERSEs / CHAPTERs
                        //    ( EXAMPLE: John 3,16.18 )
                        //    (   John 3,16 <=|=> 18 )
                        else {
                            //IF the non consecutive chunk however DOES contain a chapter reference
                            //  ( EXAMPLE: John 3,16.18 )
                            //  (   John 3,16 <=|=> 18 )
                            //  (   John 3 <=> 16 )
                            if ( strpos( $piece, "," ) ) {
                                $chapterverse = preg_split( "/,/", $piece );
                                $xchapter = $chapterverse[0];
                                $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$chapterverse[0],$chapterverse[1],$preferorigin );
                                $chapterverse[0] = $mappedReference[0];
                                $chapterverse[1] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND ( chapter = " . $chapterverse[0] . " AND verse = " . $chapterverse[1] . " )";
                            } 
                            //ELSEIF the non consecutive chunk DOES NOT contain a chapter reference
                            //  ( EXAMPLE: John 3,16.18 )
                            //  (    John 3,16 <=|=> 18 )
                            //  ( 18 )
                            else {
                                $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$xchapter,$piece,$preferorigin );
                                $xchapter = $mappedReference[0];
                                $piece = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND ( chapter = " . $xchapter . " AND verse = " . $piece . " )";
                            }
                        }

                        $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= $preferorigin;

                        $formulatedQueries->queriesversions[$formulatedQueries->nn] = $version;
                        //VERSES must be ordered by verseID in order to handle those cases where there are subverses ( usually Greek additions )
                        //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
                        //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
                        //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
                        //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
                        $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " ORDER BY verseID";
                        //$formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " ORDER BY book,chapter,verse,verseequiv";
                        if ( in_array( $version, $this->copyrightversions ) ) {
                            $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " LIMIT 30";
                        }
                        $formulatedQueries->nn++;
                    }
                } 
                //ELSEIF the request DOES NOT contain non-consecutive verses
                //    ( EXAMPLE: John 3,16 )
                else {
                    //$formulatedQueries->nn++;
                    //IF the request DOES however contain a range of consecutive verses or chapters
                    //    ( EXAMPLE: John 3,16-18 )
                    //    (    John 3,16 <=|=> 18 )
                    if ( strpos( $formulatedQueries->currentQuery, "-" ) ) {
                        $formulatedQueries->originalquery[$formulatedQueries->nn] = $formulatedQueries->currentFullQuery;
                        $fromto = preg_split( "/\-/", $formulatedQueries->currentQuery );
                        //IF there is a chapter indicator on the left hand side of the range of consecutive verses
                        //    ( EXAMPLE: John 3,16-18 )
                        //    (    John 3,16 <=|=> 18 )
                        //    (    John 3,16 )
                        if ( strpos( $fromto[0], "," ) ) {
                            //echo "We have a comma in this section of query! ". $fromto[0] . "<br />";
                            $chapterverse = preg_split( "/,/", $fromto[0] );
                            $xchapter = $chapterverse[0];
                            $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$chapterverse[0],$chapterverse[1],$preferorigin );
                            $chapterverse[0] = $mappedReference[0];
                            $chapterverse[1] = $mappedReference[1];
                            $preferorigin = $mappedReference[2];
                            //IF there is also a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-4,5 )
                            //    (    John 3,16 <=|=> 4,5 )
                            //    (    4,5 )
                            if ( strpos( $fromto[1], "," ) ) {
                                $chapterverse1 = preg_split( "/,/", $fromto[1] );
                                $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$chapterverse1[0],$chapterverse1[1],$preferorigin );
                                $chapterverse1[0] = $mappedReference[0];
                                $chapterverse1[1] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                //what if the difference between chapters is greater than 1? Say: John 3,16-6,2 ?
                                $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND ( ( chapter = " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . " )";
                                if( $chapterverse1[0] - $chapterverse[0] > 1 ) {
                                    for( $d=1;$d<( $chapterverse1[0] - $chapterverse[0] );$d++ ){
                                        $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " OR ( chapter = " . ( $chapterverse[0] + $d ) . " )";
                                    }
                                }
                                $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " OR ( chapter = " . $chapterverse1[0] . " AND verse <= " . $chapterverse1[1] . " ) )";
                            }
                            //ELSEIF there is NOT a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-18 )
                            //    (    John 3,16 <=|=> 18 )
                            //    (    18 )
                            else {
                              $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND chapter >= " . $chapterverse[0] . " AND verse >= " . $chapterverse[1];
                              $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$chapterverse[0],$fromto[1],$preferorigin );
                              $fromto[1] = $mappedReference[1];
                              $preferorigin = $mappedReference[2];
                              $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " AND chapter <= " . $mappedReference[0] . " AND verse <= " . $fromto[1];
                            }
                        }
                        //ELSEIF there is NOT a chapter/verse indicator on the left hand side of the range of consecutive verses OR chapters
                        //    this means that we are dealing with consecutive CHAPTERS and not VERSES
                        //     ( EXAMPLE: John 3-4 )
                        //     ( EXAMPLE: 3 <=|=> 4 )
                        else {
                            $mappedReference1 = $this->mapReference( $version,$formulatedQueries->currentBook,$fromto[0],null,$preferorigin );
                            $mappedReference2 = $this->mapReference( $version,$formulatedQueries->currentBook,$fromto[1],null,$preferorigin );
                            $fromto[0] = $mappedReference1[0];
                            $fromto[1] = $mappedReference2[0];
                            $preferorigin = $mappedReference1[2];
                            $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND chapter >= " . $fromto[0] . " AND chapter <= " . $fromto[1];
                        }
                    }
                    //ELSEIF the request DOES NOT contain a range of consecutive verses OR chapters
                    //    ( EXAMPLE: John 3,16 )
                    else {
                        //IF we DO have a chapter/verse indicator
                        if ( strpos( $formulatedQueries->currentQuery, "," ) ) {
                            $formulatedQueries->originalquery[$formulatedQueries->nn] = $formulatedQueries->currentFullQuery;
                            $chapterverse = preg_split( "/,/", $formulatedQueries->currentQuery );
                            $xchapter = $chapterverse[0];
                            $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$chapterverse[0],$chapterverse[1],$preferorigin );
                            $chapterverse[0] = $mappedReference[0];
                            $chapterverse[1] = $mappedReference[1];
                            $preferorigin = $mappedReference[2];
                            $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND chapter = " . $chapterverse[0] . " AND verse = " . $chapterverse[1];
                        } 
                        //ELSEIF we are dealing with just a single chapter
                        //    ( EXAMPLE: John 3 )
                        else {
                            $formulatedQueries->originalquery[$formulatedQueries->nn] = $formulatedQueries->currentFullQuery;
                            $xchapter = $formulatedQueries->currentQuery;
                            $mappedReference = $this->mapReference( $version,$formulatedQueries->currentBook,$xchapter,null,$preferorigin );
                            $preferorigin = $mappedReference[2];
                            $formulatedQueries->sqlqueries[$formulatedQueries->nn] = $formulatedQueries->sqlquery . " AND chapter = " . $mappedReference[0]; // . " AND verse = " . $piece;
                        }
                    }

                    $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= $preferorigin;

                    $formulatedQueries->queriesversions[$formulatedQueries->nn] = $version;
                    //VERSES must be ordered by verseID in order to handle those cases where there are subverses ( usually Greek additions )
                    //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
                    //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
                    //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
                    //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
                    $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " ORDER BY verseID";
                    //$formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " ORDER BY book,chapter,verse,verseequiv";
                    if ( in_array( $version, $this->copyrightversions ) ) {
                        $formulatedQueries->sqlqueries[$formulatedQueries->nn] .= " LIMIT 30";
                    }
                    $formulatedQueries->nn++;
                }

                $formulatedQueries->i++;
            }
        }

        return $formulatedQueries;

    }

    private function mapReference( $version,$book,$chapter,$verse,$preferorigin ) {
        if( in_array( $version, $this->CATHOLIC_VERSIONS ) ){
            if( $book == 19 || $book == "19" ){ //19 == Esther
                //first let's make sure that $chapter is a number
                if( gettype( $chapter ) == 'string' ){
                    //the USCCB uses letters A-F to indicate the Greek additions to Esther
                    //however, the BibleGet engine does not allow chapters that are not numbers
                    //therefore these verses have been added to the database in the same fashion as the CEI2008 layout
                    //TODO: see if there is any way of allowing letters as chapter indicators and then map them to the CEI2008 layout
                    $chapter = intval( $chapter ); 
                }
                if( $version == 'VGCL' || $version == 'DRB' ){
                    if( ( $chapter == 10 && ( ( $verse >= 4 && $verse <= 13 ) || $verse == null ) ) || ( $chapter == 11 && ( $verse == 1 || $verse == null ) ) ){
                        $chapter = 10;
                        $verse = 3;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( ( $chapter == 11 && ( ( $verse >= 2 && $verse <= 12 ) || $verse == null ) ) || ( $chapter == 12 && ( ( $verse >=1 && $verse <= 6 ) || $verse == null ) ) ){
                        $chapter = 1;
                        $verse = 1;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 13 && ( ( $verse >= 1 && $verse <= 7 ) || $verse == null ) ){
                        $chapter = 3;
                        $verse = 13;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( ( $chapter == 13 && ( ( $verse >= 8 && $verse <= 18 ) || $verse == null ) ) || ( $chapter == 14 && ( ( $verse <= 1 && $verse >= 19 ) || $verse == null ) ) ){
                        $chapter = 4;
                        $verse = 17;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 15 && ( ( $verse >= 1 && $verse <= 3 ) || $verse == null ) ){
                        $chapter = 4;
                        $verse = 8;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 15 && ( ( $verse >= 4 && $verse <= 14 ) || $verse == null ) ){
                        $chapter = 5;
                        $verse = 1;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 15 && ( ( $verse >= 15 && $verse <= 19 ) || $verse == null ) ){
                        $chapter = 5;
                        $verse = 2;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 16 ){
                        $chapter = 8;
                        $verse = 12;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    }
                }
            }
        }
        return [ $chapter, $verse, $preferorigin ];
    }

    private function getGeoIpInfo( $ipaddress ) {
        $ch = curl_init( "https://ipinfo.io/" . $ipaddress . "?token=" . IPINFO_ACCESS_TOKEN );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $this->geoip_json = curl_exec( $ch );
        if ( $this->geoip_json === false ) {
            $this->mysqli->query( "INSERT INTO curl_error ( ERRNO,ERROR ) VALUES( " . curl_errno( $ch ) . ",'" . curl_error( $ch ) . "' )" );
        }
        //Check the status of communication with ipinfo.io server
        $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $http_status == 429 ) {
            $this->geoip_json = '{"ERROR":"api limit exceeded"}';
        } else if ( $http_status == 200 ) {
            //Clean geopip_json object, ensure it is valid in any case
            //$this->geoip_json = $this->mysqli->real_escape_string( $this->geoip_json); // we don't need to escape it when it's coming from the ipinfo.io server, at least not before inserting into the database
            //Check if it's actually an object or if it's not a string perhaps
            $geoip_JSON_obj = json_decode( $this->geoip_json );
            if ( $geoip_JSON_obj === null || json_last_error() !== JSON_ERROR_NONE ) {
                //we have a problem with our geoip_json, it's probably a string with an error. We should already have escaped it           
                $this->geoip_json = '{"ERROR":"' . json_last_error() . ' <' . $this->geoip_json . '>"}';
            } else {
                $this->geoip_json = json_encode( $geoip_JSON_obj );
            }
        } else {
            $this->geoip_json = '{"ERROR":"wrong http status > ' . $http_status . '"}';
        }
    }

    private function getIpAddress(){
        $forwardedip = isset( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ? $_SERVER["HTTP_X_FORWARDED_FOR"] : "";
        $remote_address = isset( $_SERVER["REMOTE_ADDR"] ) ? $_SERVER["REMOTE_ADDR"] : "";
        $realip = isset( $_SERVER["HTTP_X_REAL_IP"] ) ? $_SERVER["HTTP_X_REAL_IP"] : "";
        $clientip = isset( $_SERVER["HTTP_CLIENT_IP"] ) ? $_SERVER["HTTP_CLIENT_IP"] : "";

        //Do our best to identify an IP address associated with the incoming request, 
        //trying first HTTP_X_FORWARDED_FOR, then REMOTE_ADDR and last resort HTTP_X_REAL_IP
        //This is useful only to protect against high volume requests from specific IP addresses or referers
        $ipaddress = $forwardedip != "" ? explode( ",", $forwardedip )[0] : "";
        if ( $ipaddress == "" ) {
            $ipaddress = $remote_address != "" ? $remote_address : "";
        }
        if ( $ipaddress == "" ) {
            $ipaddress = $realip != "" ? $realip : "";
        }
        return [ $ipaddress, $forwardedip, $remote_address, $realip, $clientip ];
    }

    private function isWhitelisted( string $domainOrIP ) {
        return array_search( $domainOrIP, $this->WhitelistedDomainsIPs );
    }

    private function haveSeenIPAddressPastTwoDaysWithSameRequest( string $ipaddress, string $xquery ) {
        return $ipaddress != "" ? $this->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $ipaddress . "' ) AND QUERY = '" . $xquery . "'  AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY )" ) : false;
    }

    private function tooManyQueriesFromSameIPAddress( string $ipaddress ) {
        return $ipaddress != "" ? $this->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $ipaddress . "' ) AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY )" ) : false;
    }

    private function validateIPAddress( string $ipaddress ) {
        return filter_var( $ipaddress, FILTER_VALIDATE_IP );
    }

    private function writeEntryToDebugFile( string $entry ) {
        file_put_contents( $this->DEBUGFILE, date( 'r' ) . "\t" . $entry . PHP_EOL, FILE_APPEND | LOCK_EX );
    }

    private function enforceQueryLimits( string $ipaddress, string $xquery ) {

        //check if we have already seen this IP Address in the past 2 days and if we have the same request already
        $ipresult = $this->haveSeenIPAddressPastTwoDaysWithSameRequest( $ipaddress, $xquery );
        if ( $ipresult ) {
            if ( $this->DEBUG_IPINFO === true ) {
                file_put_contents( $this->DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with this same request [" . $xquery . "]" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            //if more than 10 times in the past two days ( but less than 30 ) simply add message inviting to use cacheing mechanism
            if ( $ipresult->num_rows > 10 && $ipresult->num_rows < 30 ) {
                $this->addErrorMessage( 10, $xquery );
                $iprow = $ipresult->fetch_assoc();
                $this->geoip_json = $iprow[ "WHO_WHERE_JSON" ];
                $this->haveIPAddressOnRecord = true;
            }
            //if we have more than 30 requests in the past two days for the same query, deny service?
            else if ( $ipresult->num_rows > 29 ) {
                $this->addErrorMessage( 11, $xquery );
                $this->outputResult(); //this should exit the script right here, closing the mysql connection
            }
        }

        //and if the same IP address is making too many requests( >100? ) with different queries ( like copying the bible texts completely ), deny service
        $ipresult = $this->tooManyQueriesFromSameIPAddress( $ipaddress );
        if ( $ipresult ) {
            if ( $this->DEBUG_IPINFO === true ) {
                file_put_contents( $this->DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with many different requests" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            //if we 50 or more requests in the past two days, deny service?
            if ( $ipresult->num_rows > 100 ) {
                if ( $this->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with over 50 requests", FILE_APPEND | LOCK_EX );
                }
                $this->addErrorMessage( 12, $xquery );
                $this->outputResult(); //this should exit the script right here, closing the mysql connection
            }
        }

        //let's add another check for "referer" websites and how many similar requests have derived from the same origin in the past couple days
        $originres = $this->mysqli->query( "SELECT ORIGIN,COUNT( * ) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE QUERY = '" . $xquery . "' AND ORIGIN != '' AND ORIGIN = '" . $this->originHeader . "' AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY ) GROUP BY ORIGIN" );
        if ( $originres ) {
            if ( $originres->num_rows > 0 ) {
                $originRow = $originres->fetch_assoc();
                if ( array_key_exists( "ORIGIN_CNT", $originRow ) ) {
                    if ( $originRow["ORIGIN_CNT"] > 10 && $originRow["ORIGIN_CNT"] < 30 ) {
                        $this->addErrorMessage( 10, $xquery );
                    } else if ( $originRow["ORIGIN_CNT"] > 29 ) {
                        $this->addErrorMessage( 11, $xquery );
                        $this->outputResult(); //this should exit the script right here, closing the mysql connection                
                    }
                }
            }
        }
        //and we'll check for diverse requests from the same origin in the past couple days ( >100? )
        $originres = $this->mysqli->query( "SELECT ORIGIN,COUNT( * ) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE ORIGIN != '' AND ORIGIN = '" . $this->originHeader . "' AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY ) GROUP BY ORIGIN" );
        if ( $originres ) {
            if ( $originres->num_rows > 0 ) {
                $originRow = $originres->fetch_assoc();
                if ( array_key_exists( "ORIGIN_CNT", $originRow ) ) {
                    if ( $originRow["ORIGIN_CNT"] > 100 ) {
                        $this->addErrorMessage( 12, $xquery );
                        $this->outputResult(); //this should exit the script right here, closing the mysql connection
                    }
                }
            }
        }
    }

    private function geoIPInfoIsEmptyOrIsError() {
        $pregmatch = preg_quote( '{"ERROR":"', '/' );
        return $this->haveIPAddressOnRecord === false || $this->geoip_json == "" || $this->geoip_json === null || preg_match( "/" . $pregmatch . "/", $this->geoip_json );
    }

    private function getGeoIPFromLogs( string $ipaddress ) {
        if( $ipaddress != "" ){
            return $this->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $ipaddress . "' ) AND WHO_WHERE_JSON NOT LIKE '{\"ERROR\":\"%\"}'" );
        } else {
            return false;
        }
    }

    private function haveGeoIPResultsFromLogs( $geoIPFromLogs ) : bool {
        return $geoIPFromLogs->num_rows > 0;
    }

    private function getGeoIPInfoFromLogsElseOnline( string $ipaddress ) {

        $geoIPFromLogs = $this->getGeoIPFromLogs( $ipaddress );
        if ( $geoIPFromLogs !== false ) {
            if ( $this->haveGeoIPResultsFromLogs( $geoIPFromLogs ) ) {
                if ( $this->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->DEBUGFILE, "We already have valid geo_ip info [" . $this->geoip_json. "] for the IP address [" . $ipaddress . "], reusing" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $iprow = $geoIPFromLogs->fetch_assoc();
                $this->geoip_json = $iprow["WHO_WHERE_JSON"];
                $this->haveIPAddressOnRecord = true;
            } else {
                if ( $this->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->DEBUGFILE, "We do not yet have valid geo_ip info [" . $this->geoip_json. "] for the IP address [" . $ipaddress . "], nothing to reuse" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $this->getGeoIpInfo( $ipaddress );
                if ( $this->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->DEBUGFILE, "We have attempted to get geo_ip info [" . $this->geoip_json. "] for the IP address [" . $ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
            }
        } else if ( $ipaddress != "" ) {
            if ( $this->DEBUG_IPINFO === true ) {
                file_put_contents( $this->DEBUGFILE, "We do however seem to have a valid IP address [" . $ipaddress . "] , now trying to fetch info from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            $this->getGeoIpInfo( $ipaddress );
            if ( $this->DEBUG_IPINFO === true ) {
                file_put_contents( $this->DEBUGFILE, "Even in this case we have attempted to get geo_ip info [" . $this->geoip_json. "] for the IP address [" . $ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
        }

    }

    private function fillEmptyIPAddress( string $ipaddress ) : string {
        return $ipaddress != "" ? $ipaddress : "0.0.0.0";
    }

    private function normalizeErroredGeoIPInfo() {
        if ( $this->geoip_json === "" || $this->geoip_json === null ) {
            $this->geoip_json = '{"ERROR":""}';
        }
    }

    private function logQuery( $QUERY_ACTION_OBJ ) {
        $stmt = $this->mysqli->prepare( "INSERT INTO requests_log__" . $this->curYEAR . " ( WHO_IP,WHO_WHERE_JSON,HEADERS_JSON,ORIGIN,QUERY,ORIGINALQUERY,REQUEST_METHOD,HTTP_CLIENT_IP,HTTP_X_FORWARDED_FOR,HTTP_X_REAL_IP,REMOTE_ADDR,APP_ID,DOMAIN,PLUGINVERSION ) VALUES ( INET6_ATON( ? ), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )" );
        $stmt->bind_param( 'ssssssssssssss', $QUERY_ACTION_OBJ->ipaddress, $this->geoip_json, $this->jsonEncodedRequestHeaders, $this->originHeader, $QUERY_ACTION_OBJ->xquery, $QUERY_ACTION_OBJ->originalquery[$QUERY_ACTION_OBJ->i], $this->requestMethod, $QUERY_ACTION_OBJ->clientip, $QUERY_ACTION_OBJ->forwardedip, $QUERY_ACTION_OBJ->realip, $QUERY_ACTION_OBJ->remote_address, $QUERY_ACTION_OBJ->appid, $QUERY_ACTION_OBJ->domain, $QUERY_ACTION_OBJ->pluginversion );
        if ( $stmt->execute() === false ) {
            $this->addErrorMessage( "There has been an error updating the logs: ( " . $this->mysqli->errno . " ) " . $this->mysqli->error );
        }
        $stmt->close();
    }

    private function prepareResponse( object $QUERY_ACTION_OBJ ) : array {
        $currentVariant = $QUERY_ACTION_OBJ->queriesversions[$QUERY_ACTION_OBJ->i];
        $row = $QUERY_ACTION_OBJ->row;

        $row["version"]             = strtoupper( $currentVariant );
        $row["testament"]           = ( int )$row["testament"];

        $universal_booknum          = $row["book"];
        $booknum                    = array_search( $row["book"], $this->indexes[$currentVariant]["book_num"] );
        $row["bookabbrev"]          = $this->indexes[$currentVariant]["abbreviations"][$booknum];
        $row["booknum"]             = $booknum;
        $row["univbooknum"]         = $universal_booknum;
        $row["book"]                = $this->indexes[$currentVariant]["biblebooks"][$booknum];

        $row["section"]             = ( int ) $row["section"];
        unset( $row["verseID"] );
        $row["chapter"]             = ( int ) $row["chapter"];
        $row["originalquery"]       = $QUERY_ACTION_OBJ->originalquery[$QUERY_ACTION_OBJ->i];

        return $row;
    }

    private function generateResponse( object $QUERY_ACTION_OBJ ) {

        $response = $QUERY_ACTION_OBJ->response;

        if ( $this->returnType == "xml" ) {

            $thisrow = $this->bibleQuote->results->addChild( "result" );
            foreach ( $response as $key => $value ) {
                $thisrow[$key] = $value;
            }

        } elseif ( $this->returnType == "json" ) {

            $this->bibleQuote->results[] = $response;

        } elseif ( $this->returnType == "html" ) {

            if ( $response["verse"] != $QUERY_ACTION_OBJ->verse ) {
                $QUERY_ACTION_OBJ->newverse = true;
                $QUERY_ACTION_OBJ->verse = $response["verse"];
            } else {
                $QUERY_ACTION_OBJ->newverse = false;
            }

            if ( $response["chapter"] != $chapter ) {
                $QUERY_ACTION_OBJ->newchapter = true;
                $QUERY_ACTION_OBJ->newverse = true;
                $chapter = $response["chapter"];
            } else {
                $QUERY_ACTION_OBJ->newchapter = false;
            }

            if ( $response["book"] != $book ) {
                $QUERY_ACTION_OBJ->newbook = true;
                $QUERY_ACTION_OBJ->newchapter = true;
                $QUERY_ACTION_OBJ->newverse = true;
                $book = $response["book"];
            } else {
                $QUERY_ACTION_OBJ->newbook = false;
            }

            if ( $response["version"] != $version ) {
                $QUERY_ACTION_OBJ->newversion = true;
                $QUERY_ACTION_OBJ->newbook = true;
                $QUERY_ACTION_OBJ->newchapter = true;
                $QUERY_ACTION_OBJ->newverse = true;
                $version = $response["version"];
            } else {
                $QUERY_ACTION_OBJ->newversion = false;
            }

            if ( $QUERY_ACTION_OBJ->newversion ) {
                $variant = $this->bibleQuote->createElement( "p", $response["version"] );
                if ( $QUERY_ACTION_OBJ->i > 0 ) {
                    $br = $this->bibleQuote->createElement( "br" );
                    $variant->insertBefore( $br, $variant->firstChild );
                }
                $variant->setAttribute( "class", "version bibleVersion" );
                $this->div->appendChild( $variant );
            }

            if ( $QUERY_ACTION_OBJ->newbook || $QUERY_ACTION_OBJ->newchapter ) {
                $citation = $this->bibleQuote->createElement( "p", $response["book"] . "&nbsp;" . $response["chapter"] );
                $citation->setAttribute( "class", "book bookChapter" );
                $this->div->appendChild( $citation );
                $citation1 = $this->bibleQuote->createElement( "p" );
                $citation1->setAttribute( "class", "verses versesParagraph" );
                $this->div->appendChild( $citation1 );
                $metainfo = $this->bibleQuote->createElement( "input" );
                $metainfo->setAttribute( "type", "hidden" );
                $metainfo->setAttribute( "class", "originalQuery" );
                $metainfo->setAttribute( "value", $response["originalquery"] );
                $this->div->appendChild( $metainfo );
                $metainfo1 = $this->bibleQuote->createElement( "input" );
                $metainfo1->setAttribute( "type", "hidden" );
                $metainfo1->setAttribute( "class", "bookAbbrev" );
                $metainfo1->setAttribute( "value", $response["bookabbrev"] );
                $this->div->appendChild( $metainfo1 );
                $metainfo2 = $this->bibleQuote->createElement( "input" );
                $metainfo2->setAttribute( "type", "hidden" );
                $metainfo2->setAttribute( "class", "bookNum" );
                $metainfo2->setAttribute( "value", $response["booknum"] );
                $this->div->appendChild( $metainfo2 );
                $metainfo3 = $this->bibleQuote->createElement( "input" );
                $metainfo3->setAttribute( "type", "hidden" );
                $metainfo3->setAttribute( "class", "univBookNum" );
                $metainfo3->setAttribute( "value", $response["univbooknum"] );
                $this->div->appendChild( $metainfo3 );
            }
            if ( $QUERY_ACTION_OBJ->newverse ) {
                $versicle = $this->bibleQuote->createElement( "span", $response["verse"] );
                $versicle->setAttribute( "class", "sup verseNum" );
                $citation1->appendChild( $versicle );
            }

            $text = $this->bibleQuote->createElement( "span", $response["text"] );
            $text->setAttribute( "class", "text verseText" );
            $citation1->appendChild( $text );

        }
    }

    private function doQueries( object $formulatedQueries ) {

        $QUERY_ACTION_OBJ = new stdClass();
        $QUERY_ACTION_OBJ->sqlqueries       = $formulatedQueries->sqlqueries;
        $QUERY_ACTION_OBJ->queriesversions  = $formulatedQueries->queriesversions;
        $QUERY_ACTION_OBJ->originalquery    = $formulatedQueries->originalquery;

        $QUERY_ACTION_OBJ->appid            = $this->DATA["appid"]          != "" ? $this->DATA["appid"]            : "unknown";
        $QUERY_ACTION_OBJ->domain           = $this->DATA["domain"]         != "" ? $this->DATA["domain"]           : "unknown";
        $QUERY_ACTION_OBJ->pluginversion    = $this->DATA["pluginversion"]  != "" ? $this->DATA["pluginversion"]    : "unknown";

        [ $ipaddress, $forwardedip, $remote_address, $realip, $clientip ] = $this->getIpAddress();
        $QUERY_ACTION_OBJ->ipaddress        = $ipaddress;
        $QUERY_ACTION_OBJ->forwardedip      = $forwardedip;
        $QUERY_ACTION_OBJ->remote_address   = $remote_address;
        $QUERY_ACTION_OBJ->realip           = $realip;
        $QUERY_ACTION_OBJ->clientip         = $clientip;
        $QUERY_ACTION_OBJ->i                = 0;
        $QUERY_ACTION_OBJ->xquery           = "";

        // First we initialize some variables and flags with default values
        $QUERY_ACTION_OBJ->version        = "";
        $QUERY_ACTION_OBJ->newversion     = false;
        $QUERY_ACTION_OBJ->book           = "";
        $QUERY_ACTION_OBJ->newbook        = false;
        $QUERY_ACTION_OBJ->chapter        = 0;
        $QUERY_ACTION_OBJ->newchapter     = false;

        if ( $this->validateIPAddress( $QUERY_ACTION_OBJ->ipaddress ) === false ) {
            $this->addErrorMessage( "The BibleGet API endpoint cannot be used behind a proxy that hides the IP address from which the request is coming. No personal or sensitive data is collected by the API, however IP addresses are monitored to prevent spam requests. If you believe there is an error because this is not the case, please contact the developers so they can look into the situtation.", $xquery );
            $this->outputResult();
        }

        $notWhitelisted = ( $this->isWhitelisted( $QUERY_ACTION_OBJ->domain ) === false && $this->isWhitelisted( $QUERY_ACTION_OBJ->ipaddress ) === false );

        foreach ( $QUERY_ACTION_OBJ->sqlqueries as $xquery ) {

            $QUERY_ACTION_OBJ->xquery = $xquery;

            if ( $notWhitelisted ) {
                $this->enforceQueryLimits( $QUERY_ACTION_OBJ->ipaddress, $xquery );
            }

            $result = $this->mysqli->query( $xquery );
            if ( $result ) {

                $this->incrementGoodQueryCount();

                if ( $this->geoIPInfoIsEmptyOrIsError() ) {
                    if ( $this->DEBUG_IPINFO === true ) {
                        file_put_contents( $this->DEBUGFILE, "Either we have not yet seen the IP address [" . $QUERY_ACTION_OBJ->ipaddress . "] in the past 2 days or we have no geo_ip info [" . $this->geoip_json. "]" . PHP_EOL, FILE_APPEND | LOCK_EX );
                    }
                    $this->getGeoIPInfoFromLogsElseOnline( $QUERY_ACTION_OBJ->ipaddress );
                }

                $QUERY_ACTION_OBJ->ipaddress = $this->fillEmptyIPAddress( $QUERY_ACTION_OBJ->ipaddress );
                $this->normalizeErroredGeoIPInfo();

                $this->logQuery( $QUERY_ACTION_OBJ );

                $verse = "";
                $newverse = false;
                while ( $row = $result->fetch_assoc() ) {

                    $QUERY_ACTION_OBJ->row = $row;
                    $QUERY_ACTION_OBJ->response = $this->prepareResponse( $QUERY_ACTION_OBJ );
                    $this->generateResponse( $QUERY_ACTION_OBJ );

                }
            } else {
                $this->addErrorMessage( 9, $xquery );
            }
            $QUERY_ACTION_OBJ->i++;
        }
    }


    public function Init() {

        switch( $this->returnType ){
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

            $queries = $this->queryStrClean();
            if( $this->detectedNotation === "MIXED" ){
                $this->addErrorMessage( "Mixed notations have been detected, please use either english or european notation." );
                $this->outputResult();
            }
    
            //at least the first query must start with a book reference, which may have a number from 1 to 3 at the beginning
            //echo "matching against: ".$queries[0]."<br />";
            if ( $this->queryViolatesAnyRuleOf( $queries[0], [ self::QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR ] ) ) {
                $this->outputResult();
            }

            $validatedQueries = $this->validateQueries( $queries );

            if( $validatedQueries !== false ){
                $usedvariants = property_exists( $validatedQueries, 'usedvariants' ) ? $validatedQueries->usedvariants : false;
                if ( !is_array( $usedvariants ) ) {
                    $this->outputResult();
                } else {
                    // 3 -> TRANSLATE BIBLE NOTATION QUERIES TO MYSQL QUERIES
                    $formulatedQueries = $this->formulateSQLQueries( $validatedQueries );
    
                    // 5 -> DO MYSQL QUERIES AND COLLECT RESULTS IN OBJECT 
                    $this->doQueries( $formulatedQueries );
    
                    // 6 -> OUTPUT RESULTS FORMATTED ACCORDING TO REQUESTED RETURN TYPE
                    $this->outputResult();
                }
            } else {
                $this->outputResult();
            }


        }

    }

}



if( isset( $_SERVER['CONTENT_TYPE'] ) && !in_array( $_SERVER['CONTENT_TYPE'], BIBLEGET_QUOTE::$allowedContentTypes ) ){
    header( $_SERVER["SERVER_PROTOCOL"]." 415 Unsupported Media Type", true, 415 );
    die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ',BIBLEGET_QUOTE::$allowedContentTypes ).', but your Content Type was '.$_SERVER['CONTENT_TYPE'].'"}' );
} else if ( isset( $_SERVER['CONTENT_TYPE'] ) && $_SERVER['CONTENT_TYPE'] === 'application/json' ) {
    $json = file_get_contents( 'php://input' );
    $data = json_decode( $json,true );
    if( NULL === $json || "" === $json ){
        header( $_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400 );
        die( '{"error":"No JSON data received in the request: <' . $json . '>"' );
    } else if ( json_last_error() !== JSON_ERROR_NONE ) {
        header( $_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400 );
        die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
    } else {
        $BIBLEQUOTE = new BIBLEGET_QUOTE( $data );
        //$BIBLEQUOTE->DEBUG_REQUESTS = true;
        $BIBLEQUOTE->Init();
    }
} else {
  switch( strtoupper( $_SERVER["REQUEST_METHOD"] ) ) {
      case 'POST':
          $BIBLEQUOTE = new BIBLEGET_QUOTE( $_POST );
          //$BIBLEQUOTE->DEBUG_REQUESTS = true;
          $BIBLEQUOTE->Init();
          break;
      case 'GET':
          $BIBLEQUOTE = new BIBLEGET_QUOTE( $_GET );
          //$BIBLEQUOTE->DEBUG_REQUESTS = true;
          $BIBLEQUOTE->Init();
          break;
      default:
          header( $_SERVER["SERVER_PROTOCOL"]." 405 Method Not Allowed", true, 405 );
          die( '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are '.implode( ' and ',BIBLEGET_QUOTE::$allowedRequestMethods ).', but your Request Method was '.strtoupper( $_SERVER['REQUEST_METHOD'] ).'"}' );
  }
}

