<?php 
/**
 * BibleGet I/O Project Service Endpoint
 * listens on both GET requests and POST requests
 * whether ajax or not
 * accepts all cross-domain requests
 * is CORS enabled (as far as I understand it)
 * 
 * ENDPOINT URL:    https://query.bibleget.io/search.php
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
 */

//ini_set('display_errors', 1);
//error_reporting(E_ALL);

//TODO: implement advanced search with fulltext boolean operators, multiple keywords, negating keywords...

define("ENDPOINT_VERSION", "3.0");

/*************************************************************
 * SET HEADERS TO ALLOW ANY KIND OF REQUESTS FROM ANY ORIGIN * 
 * AND CONTENT-TYPE BASED ON REQUESTED RETURN TYPE           *
 ************************************************************/

// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}
// Access-Control headers are received during OPTIONS requests
if (isset($_SERVER['REQUEST_METHOD'])) {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST");         
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
}


/****************************************
 * START BUILDING BIBLEGET SEARCH CLASS * 
 ***************************************/
 
class BIBLEGET_SEARCH {

    static public $returntypes = array("json","xml","html"); // only json and xml will be actually supported, html makes no sense for metadata
    static public $allowed_accept_headers = array("application/json", "application/xml", "text/html");
    static public $allowed_content_types = array("application/json" , "application/x-www-form-urlencoded");
    static public $allowed_request_methods = array("GET","POST");

    private $DATA;           //all request parameters
    private $returntype;     //which type of data to return (json, xml or html)
    private $requestHeaders;
    private $acceptHeader;
    private mysqli $mysqli;         //instance of database
    private $validversions;  //array of Bible versions supported by the BibleGet project, to check against
    private $indexes;
    private $dontSearch;
    private $is_ajax;
    private stdClass|simpleXMLElement|DOMDocument $search;         //object with json, xml or html data to return
    private DOMElement $div;
    private DOMElement $err;
    private DOMElement $inf;

    function __construct($DATA){
        $this->DATA = $DATA;
        $this->requestHeaders = getallheaders();
        $this->contenttype = isset($_SERVER['CONTENT_TYPE']) && in_array($_SERVER['CONTENT_TYPE'],self::$allowed_content_types) ? $_SERVER['CONTENT_TYPE'] : NULL;
        $this->acceptHeader = isset($this->requestHeaders["Accept"]) && in_array($this->requestHeaders["Accept"],self::$allowed_accept_headers) ? self::$returntypes[array_search($this->requestHeaders["Accept"],self::$allowed_accept_headers)] : "";
        $this->returntype = (isset($DATA["return"]) && in_array(strtolower($DATA["return"]),self::$returntypes)) ? strtolower($DATA["return"]) : ($this->acceptHeader !== "" ? $this->acceptHeader : self::$returntypes[0]);
        $this->is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $this->exactmatch = isset($DATA["exactmatch"]) && $DATA["exactmatch"] === "true" ? true : false;
        //first add english prepositions to our dontSearch array
        $prepositions_conjunctions = ["about","above","across","after","against","ahead","along","already","also","among","and","around","as","at",
                      "back","because","behind","before","below","beside","between","both","bottom","but","by",
                      "earlier","either",
                      "for","from","front",
                      "how",
                      "if","in","inside","instead","into",
                      "just",
                      "later","less",
                      "more",
                      "near","never","nor", "not","now",
                      "of","off","on","once","only","or","out","outside","over",
                      "rather",
                      "since", "so",
                      "through","till","to","top","toward",
                      "under","until",
                      "yet",
                      "when","whenever","whether","while","with","within"];
        //then add english pronouns
        $articles_pronouns =  ["I", "me", "my", "mine", "myself", 
                      "you", "your", "yours", "yourself", 
                      "he", "him", "his", "himself", 
                      "she", "her", "hers", "herself", 
                      "it", "its", "itself", "this", "that", "these", "those",
                      "a", "an", "the", "they", "their", "theirs", "them", "themselves",
                      "each", "few", "many", "much", "some", "who", "whoever", "whose", "someone", "everyone", "everybody"];
        $verbs = ["am", "is", "are", "have", "has", "had", "was", "were", "would", "can", "could", "shall", "should", "will", "might", "must", "being", "been", "do", "does", "did", "done", "be", "being", "been"];
        $congiunzioni = ["intorno","sopra","attraverso","dopo","contro","davanti","avanti","accanto","anche","tra","fra","e","come","ad",
                        "dietro","dopo","prima","sotto","vicino","però","anzi","intanto","da",
                        "oppure","per","cui","se","in","dentro","soltanto","più","meno","mai",
                        "nemmeno","non","adesso","di","su","sul","sulla","nel","nella","negli","sugli","o","fuori",
                        "piuttosto","anziché","perché","invece","affinché","fintanto","siccome","così",
                        "fino","al","alla","agli","verso","ancora","già",
                        "quando","sia","che","durante","mentre","con"
                        ];
        $articoli_pronomi = ["io","mio","mia","miei","mie","stesso",
                          "tu","tuo","tua","tuoi","tue",
                          "lui","egli","suo","sua","suoi","sue",
                          "lei","ella",
                          "questo","questa","questi","queste","quello","quella","quelli","quegli","quelle",
                          "un","una","uno","il","la","loro","essi","esse","ognuno","ognuna",
                          "ciascuno","ciascuna","molti","molte","alcuni","alcune","certi","certe","alcuno","alcuna","alcuni","alcune",
                          "chi","quale","quali","chiunque","qualcuno","qualcuna","qualunque","tutto","tutta","tutti","tutte","quanto","quanta","quanti","quante"
                        ];
        $verbi = ["sono", "sei", "è", "siamo", "siete", "sono", "ero", "eri", "era", "eravamo", "eravate", "erano", "sarò", "sarai", "sarà", "saremo", "sarete", "saranno", "fui", "fu", "fummo", "furono", 
                  "ho", "hai", "ha", "abbiamo", "avete", "hanno", "avrò", "avrai", "avrà", "avremo", "avrete", "avranno", "avevo", "avevi", "aveva", "avevamo", "avevate", "avevano", "ebbi", "ebbe", "ebbero",
                  "faccio", "fai", "fa", "facciamo", "fate", "fanno", "farò", "farai", "farà", "faremo", "farete", "faranno", "feci", "fece", "fecero", "facevo", "facevi", "faceva", "facevamo", "facevate", "facevano",
                  "avrebbe", "dovrebbe", "potrebbe", "posso", "puoi", "può", "possiamo", "potete", "possono", "potrò", "potrai", "potrà", "potremo", "potrete", "potranno", "potevo", "potevi", "poteva", "potevamo", "potevate", "potevano", "potetti", "poté", "potemmo", "potettero",
                  "devo", "devi", "deve", "dobbiamo", "dovete", "devono", "dovrò", "dovrai", "dovrà", "dovremo", "dovrete", "dovranno", "dovevo", "dovevi", "doveva", "dovevamo", "dovevate", "dovevano", "dovetti", "dovette", "dovemmo", "dovettero"];
        $this->dontSearch = array_merge($prepositions_conjunctions, $articles_pronouns,$verbs,$congiunzioni,$articoli_pronomi,$verbi);
    }
    
    public function Init(){
        switch($this->returntype){
          case "xml":
            header('Content-Type: application/xml; charset=utf-8');
            break;
          case "json":
            header('Content-Type: application/json; charset=utf-8');
            break;
          case "html":
            header('Content-Type: text/html; charset=utf-8');
            break;
          default:
            header('Content-Type: application/json; charset=utf-8');
        }
        
        $this->SearchInit();
        
        $this->mysqli        = $this->dbConnect();
        $this->validversions = $this->getValidVersions();
        if(isset($this->DATA["version"]) && $this->DATA["version"] != ""){
          $this->DATA["version"] = strtoupper($this->DATA["version"]);
          if($this->checkValidVersions($this->DATA["version"])){
            $this->indexes = $this->prepareIndexes(array(strtoupper($this->DATA["version"])));
          }
          else{
            $this->addErrorMessage("The Bible version requested is not a valid Bible version");
            $this->outputResult();
          }
        }
        else{
          $this->addErrorMessage("No Bible version to search from has been requested");
          $this->outputResult();
        }

        if(isset($this->DATA["query"]) && $this->DATA["query"] != ""){
          switch(strtolower($this->DATA["query"])){
            case "keywordsearch":
              if(isset($this->DATA["keyword"]) && $this->DATA["keyword"] != ""){
                $this->searchByKeyword($this->DATA["keyword"],$this->DATA["version"]);
              }
              else{
                $this->addErrorMessage("No keyword to search for has been requested. Please indicate a keyword using the 'keyword' parameter.");
                $this->outputResult();
              }
              break;
            default:
              exit(0);  
          }
        }
        else{
          $this->addErrorMessage("No action has been requested. Please indicate an action using the 'query' parameter.");
          $this->outputResult();
        }
        
    }

    private function dbConnect(){
    
        define("BIBLEGETIOQUERYSCRIPT","iknowwhythisishere");
        
        $dbCredentials = "dbcredentials.php";
        //search for the database credentials file at least three levels up...
        if(file_exists($dbCredentials)){
            include $dbCredentials;
        } else if (file_exists("../" . $dbCredentials)){
            include "../{$dbCredentials}";
        } else if (file_exists("../../" . $dbCredentials)){
            include "../../{$dbCredentials}";
        }
         
        $mysqli = new mysqli(SERVER,DBUSER,DBPASS,DATABASE);
      
        if ($mysqli->connect_errno) {
          $this->addErrorMessage("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
          $this->outputResult();
        }
        $mysqli->set_charset("utf8");
        /*
        if (!$mysqli->set_charset("utf8")) {
          //printf("Error loading character set utf8: %s\n", $mysqli->error);
        }
        else {
          //printf("Current character set: %s\n", $mysqli->character_set_name());
        }
        */
        return $mysqli;
    }

    private function SearchInit(){

      switch($this->returntype){
        case "json":
          $search = new stdClass();
          $search->results = array();
          $search->errors = array();
          $search->info = array();
        break;
        case "xml":
          $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?"."><BibleQuote/>";
          $search = new simpleXMLElement($root);
          $search->addChild("errors");
          $search->addChild("info");
          $search->addChild("results");
        break;
        case "html":
          $search = new DOMDocument();
          $html = "<!DOCTYPE HTML><head><title>BibleGet Query Result</title></head><body></body>"; //we won't actually output this, but it is needed to create our DomDocument object
          $search->loadHTML($html);
          $div = $search->createElement("div");
          $div->setAttribute("class","results bibleSearch");
          $err = $search->createElement("div");
          $err->setAttribute("class","errors bibleSearch");
          $inf = $search->createElement("div");
          $inf->setAttribute("class","info bibleSearch");
          $this->div        = $div;
          $this->err        = $err;
          $this->inf        = $inf;
        break;
      }
      
      $this->search = $search;

    }
    

    private function addErrorMessage($str){  
    
      switch($this->returntype){
        case "json":
          $error = array();
          $error["errMessage"] = $str;
          $this->search->errors[] = $error;
        break;
        case "xml":
          $err_row = $this->search->errors->addChild( "error", $str );
        break;
        case "html":
          $elements = array();

          $elements[0] = $this->search->createElement("table");
          $elements[0]->setAttribute("id","errorsTbl");
          $elements[0]->setAttribute("class","errorsTbl");
          $this->err->appendChild($elements[0]);
          
          $elements[1] = $this->search->createElement("tr");
          $elements[1]->setAttribute("id","errorsRow1");
          $elements[1]->setAttribute("class","errorsRow1");
          $elements[0]->appendChild($elements[1]);
          
          $elements[2] = $this->search->createElement("td","errMessage");
          $elements[2]->setAttribute("class","errMessage");
          $elements[2]->setAttribute("id","errMessage");
          $elements[1]->appendChild($elements[2]);
      
          $elements[3] = $this->search->createElement("td",$str);
          $elements[3]->setAttribute("class","errMessageVal");
          $elements[3]->setAttribute("id","errMessageVal");
          $elements[1]->appendChild($elements[3]);
        break;
      }
    }


    private function searchByKeyword($keyword,$version){

      //we have already ensured that $version is a valid version, so let's get right to business
      // PREPARE ARRAY OF SEARCH RESULTS

      //TODO: we should be able to gather the list of latin versions available automatically
      // however is it worth the trouble of doing a query against the metadata endpoint?
      // how many latin versions are we going to be adding? perhaps just easier to leave it hardcoded here...
      if(($version == 'NVBSE' || $version == 'VGCL') && (strpos($keyword,'ae') || strpos($keyword,'oe')) ){
        $keyword = str_replace('ae','æ',$keyword);
        $keyword = str_replace('oe','œ',$keyword);
      }
      $searchresults = array();
      $keyword = $this->mysqli->real_escape_string($keyword);
      $querystring = "";
      if($this->exactmatch && mb_strlen($keyword) > 1 && !in_array($keyword,$this->dontSearch) ){
        $querystring = "SELECT * FROM `{$version}` WHERE text RLIKE '[[:<:]]{$keyword}[[:>:]]'";
      } else if($this->exactmatch === false && mb_strlen($keyword) > 1) {
        $querystring = "SELECT * FROM `{$version}` WHERE MATCH(text) AGAINST ('{$keyword}*' IN BOOLEAN MODE)";
      } else {
        $this->addErrorMessage("<p>Query string cannot be a single character</p>");
        $this->outputResult();
      }
      $result1 = $this->mysqli->query($querystring);
      if($result1){
          while($row = mysqli_fetch_assoc($result1)){
            $row["version"] = strtoupper($version);
            $universal_booknum = $row["book"];
            $booknum = array_search($row["book"],$this->indexes[$version]["book_num"]);
            $row["bookabbrev"] = $this->indexes[$version]["abbreviations"][$booknum];
            $row["booknum"] = $booknum;
            $row["univbooknum"] = $universal_booknum;
            $row["book"] = $this->indexes[$version]["biblebooks"][$booknum];
            $row["originalquery"] = $this->indexes[$version]["abbreviations"][$booknum] . $row["chapter"] . ":" . $row["verse"];
            unset($row["verseID"]);
            $searchresults[] = $row;
          }   
      }
      else{
        $this->addErrorMessage("<p>MySQL ERROR ".$this->mysqli->errno . ": " . $this->mysqli->error."</p>");
        $this->outputResult();
      }
      
      switch($this->returntype){
        case "json":
          $this->search->results = $searchresults;
        break;
        case "xml":
          foreach($searchresults as $key => $row){
            //TODO: how do we want to build this XML representation?
            $thisrow = $this->search->results->addChild('result');
            foreach($row as $key => $value){
              $thisrow[$key] = $value;
            }
          }
        break;
        case "html":
      
          $TABLE = $this->search->createElement("table");
          $TABLE->setAttribute("id","SearchResultsTbl");
          $TABLE->setAttribute("class","SearchResultsTbl");
          $this->div->appendChild($TABLE);
          $TBODY = $this->search->createElement("tbody");
          $TABLE->appendChild($TBODY);

          foreach($searchresults as $key => $value){
              $NEWROW = $this->search->createElement("tr");
              $TBODY->appendChild($NEWROW);
          
              foreach($value as $col => $cell){
                  $NEWCELL = $this->search->createElement("td",$cell);
                  $NEWROW->appendChild($NEWCELL);
              }
          }
      } 
      
      $this->outputResult();
      
    }


    private function getValidVersions(){

        $validversions = [];
        $result = $this->mysqli->query("SELECT * FROM versions_available");
        if($result){
            while($row = mysqli_fetch_assoc($result)){
                $validversions[] = $row["sigla"];
            }
        }
        else{
            $this->addErrorMessage("<p>MySQL ERROR ".$this->mysqli->errno . ": " . $this->mysqli->error."</p>");
            $this->outputResult();
        }
        return $validversions;

    }

    private function checkValidVersions($value){
        return(in_array($value,$this->validversions));
    }

    private function prepareIndexes($versions){

        $indexes = [];

        foreach($versions as $variant){  
            $abbreviations  = [];
            $bbbooks        = [];
            $chapter_limit  = [];
            $verse_limit    = [];
            $book_num       = [];

            // fetch the index information for the requested version from the database and load it into our arrays
            $result = $this->mysqli->query("SELECT * FROM ".$variant."_idx");
            if($result){
                while($row = $result->fetch_assoc()){
                    $abbreviations[]    = $row["abbrev"];
                    $bbbooks[]          = $row["fullname"];
                    $chapter_limit[]    = $row["chapters"];
                    $verse_limit[]      = explode(",",$row["verses_last"]);
                    $book_num[]         = $row["book"];
                }
            }

            $indexes[$variant]["abbreviations"] = $abbreviations;
            $indexes[$variant]["biblebooks"]    = $bbbooks;
            $indexes[$variant]["chapter_limit"] = $chapter_limit;
            $indexes[$variant]["verse_limit"]   = $verse_limit;
            $indexes[$variant]["book_num"]      = $book_num;  

        }

        return $indexes;

    }
    
    
    private function outputResult(){

        switch($this->returntype){
            case "json":
              //print_r($metadata->results[52]);
              $this->search->info["ENDPOINT_VERSION"] = ENDPOINT_VERSION;
              $this->search->info["keyword"] = $this->DATA["keyword"];
              $this->search->info["version"] = $this->DATA["version"];
              echo json_encode($this->search, JSON_UNESCAPED_UNICODE);
              break;
            case "xml":
              $this->search->info->addAttribute("ENDPOINT_VERSION", ENDPOINT_VERSION);
              $this->search->info->addAttribute("keyword", $this->DATA["keyword"]);
              $this->search->info->addAttribute("version", $this->DATA["version"]);
              echo $this->search->asXML();
              break;
            case "html":
              $this->search->appendChild( $this->err );
              $this->search->appendChild( $this->div );
              $info = $this->search->createElement("input");
              $info->setAttribute("type", "hidden");
              $info->setAttribute("name", "ENDPOINT_VERSION");
              $info->setAttribute("value", ENDPOINT_VERSION);
              $this->inf->appendChild($info);
              $info1 = $this->search->createElement("input");
              $info1->setAttribute("type", "hidden");
              $info1->setAttribute("name", "keyword");
              $info1->setAttribute("value", $this->DATA["keyword"]);
              $this->inf->appendChild($info1);
              $info2 = $this->search->createElement("input");
              $info2->setAttribute("type", "hidden");
              $info2->setAttribute("name", "version");
              $info2->setAttribute("value", $this->DATA["version"]);
              $this->inf->appendChild($info2);
              $this->search->appendChild( $this->inf );
              echo $this->search->saveHTML($this->div);
              echo $this->search->saveHTML($this->err);
              echo $this->search->saveHTML($this->inf);
              break;
        }

        $this->mysqli->close();
        exit(0);  
    }


}

/*****************************************
 *      END BIBLEGET SEARCH CLASS        *
 ****************************************/

if(isset($_SERVER['CONTENT_TYPE']) && !in_array($_SERVER['CONTENT_TYPE'],BIBLEGET_SEARCH::$allowed_content_types)){
    header($_SERVER["SERVER_PROTOCOL"]." 415 Unsupported Media Type", true, 415);
    die('{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode(' and ',BIBLEGET_SEARCH::$allowed_content_types).', but your Content Type was '.$_SERVER['CONTENT_TYPE'].'"}');
} else if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $json = file_get_contents('php://input');
    $data = json_decode($json,true);
    if(NULL === $json || "" === $json){
        header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400);
        die('{"error":"No JSON data received in the request: <' . $json . '>"');
    } else if (json_last_error() !== JSON_ERROR_NONE) {
        header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400);
        die('{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}');
    } else {
        $SEARCH = new BIBLEGET_SEARCH($data);
        $SEARCH->Init();
    }
} else {
    switch(strtoupper($_SERVER["REQUEST_METHOD"])) {
        case 'POST':
            $SEARCH = new BIBLEGET_SEARCH($_POST);
            $SEARCH->Init();
            break;
        case 'GET':
            $SEARCH = new BIBLEGET_SEARCH($_GET);
            $SEARCH->Init();
            break;
        default:
            header($_SERVER["SERVER_PROTOCOL"]." 405 Method Not Allowed", true, 405);
            die('{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are '.implode(' and ',BIBLEGET_SEARCH::$allowed_request_methods).', but your Request Method was '.strtoupper($_SERVER['REQUEST_METHOD']).'"}');
    }
}

?>
