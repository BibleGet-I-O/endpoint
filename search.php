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
 */


define("ENDPOINT_VERSION", "2.4");

/*************************************************************/
/* SET HEADERS TO ALLOW ANY KIND OF REQUESTS FROM ANY ORIGIN */ 
/* AND CONTENT-TYPE BASED ON REQUESTED RETURN TYPE           */
/*************************************************************/

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

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']);


/****************************************
 * START BUILDING BIBLEGET SEARCH CLASS * 
 ***************************************/
 
class BIBLEGET_SEARCH {
    
    static private $returntypes = array("json","xml","html"); // only json and xml will be actually supported, html makes no sense for metadata

    private $DATA;
    private $returntype;
    private $search;
    private $mysqli;
    private $validversions; 
    
    function __construct($DATA){
        
        $this->DATA = $DATA;
        
        $this->returntype = (isset($DATA["return"]) && in_array(strtolower($DATA["return"]),self::$returntypes)) ? strtolower($DATA["return"]) : "json";
                
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
        
        $temp = $this->SearchInit();
        $this->search   = $temp[0];
        $this->div      = $temp[1];
        $this->err      = $temp[2];
        
        $this->mysqli   = self::dbConnect();
        
        if(isset($this->DATA["query"]) && $this->DATA["query"] != ""){
          switch(strtolower($this->DATA["query"])){
            case "keywordsearch":
              if(isset($this->DATA["keyword"]) && $this->DATA["keyword"] != ""){
                if(isset($this->DATA["version"]) && $this->DATA["version"] != ""){
                  //TODO: check if the version is a valid supported version of the Bible
                  $this->searchByKeyword($this->DATA["keyword"],$this->DATA["version"]);
                }
              }
              break;
            default:
              exit(0);  
          }
        }
        
    }

    static private function dbConnect(){
    
        define("BIBLEGETIOQUERYSCRIPT","iknowwhythisishere");
        
        include 'dbcredentials.php';
         
        $mysqli = new mysqli(SERVER,DBUSER,DBPASS,DATABASE);
      
        if ($mysqli->connect_errno) {
          $this->addErrorMessage("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
          $this->outputResult();
        }
        if (!$mysqli->set_charset("utf8")) {
          //printf("Error loading character set utf8: %s\n", $mysqli->error);
        } else {
          //printf("Current character set: %s\n", $mysqli->character_set_name());
        }
        return $mysqli;
    }

    private function SearchInit(){

      $err = NULL;
      $div = NULL;

      if($this->returntype == "json"){
        $search = new stdClass();
        $search->results = array();
        $search->errors = array();
      }
      else if($this->returntype == "xml"){
        $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?"."><Results/>";
        $search = new simpleXMLElement($root);
        $search->addChild("Errors");
      }
      else if($this->returntype == "html"){
        $search = new DOMDocument();
        $html = "<!DOCTYPE HTML><head><title>BibleGet Query Result</title></head><body></body>";
        $search->loadHTML($html);
        $div = $search->createElement("div");
        $div->setAttribute("class","results");
        $div->setAttribute("id","results");
        $err = $search->createElement("div");
        $err->setAttribute("class","errors");
        $err->setAttribute("id","errors");
      }
      
      return array($search,$div,$err);

    }
    

    private function addErrorMessage($str){  
    
      if($this->returntype=="json"){
        $error = array();
        $error["errMessage"] = $str;    
        $this->search->errors[] = $error;
      }
      elseif($this->returntype=="xml"){
        $err_row = $this->search->Errors->addChild("error",$str);
      }
      elseif($this->returntype=="html"){
    
        $elements = array();
        $attributes = array();
    
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
      }
    }
    
    
    private function searchByKeyword($keyword,$version){
      
      //we have already ensured that $version is a valid version, so let's get right to business
      // PREPARE ARRAY OF SEARCH RESULTS
      $searchresults = array();
      if($result1 = $this->mysqli->query("SELECT * FROM '".$version."' WHERE text LIKE '%".$keyword."%'")){
          while($row1 = mysqli_fetch_assoc($result1)){
            //$searchresults[] = 
          }        
      }
      else{
        $this->addErrorMessage("<p>MySQL ERROR ".$this->mysqli->errno . ": " . $this->mysqli->error."</p>");
        $this->outputResult();
      }
      
      if($this->returntype=="json"){
        $this->search->results = $searchresults;
      } 
      else if($this->returntype=="xml"){
        foreach($searchresults as $key => $value){
          //TODO: how to do we want to build this XML representation
        }
      }
      else if($this->returntype=="html"){
      /*  
        $TABLE = $this->search->createElement("table");
        $TABLE->setAttribute("id","SearchResultsTbl");
        $TABLE->setAttribute("class","SearchResultsTbl");
        $this->div->appendChild($TABLE);        
        
        $THEAD = $this->search->createElement("thead");
        $TABLE->appendChild($THEAD);
        
        $NEWROW = $this->search->createElement("tr");
        $THEAD->appendChild($NEWROW);
        
        $NEWCOL = array();
        $NEWCOL["BOOKIDX"] = $this->search->createElement("td","BOOK INDEX");
        $NEWROW->appendChild($NEWCOL["BOOKIDX"]);
        $NEWCOL["LANGUAGE"] = $this->search->createElement("td","LANGUAGE");
        $NEWROW->appendChild($NEWCOL["LANGUAGE"]);
        $NEWCOL["VALUE"] = $this->search->createElement("td","VALUE");
        $NEWROW->appendChild($NEWCOL["VALUE"]);
        
        $TBODY = $this->search->createElement("tbody");
        $TABLE->appendChild($TBODY);

        foreach($biblebooks as $key => $value){
            $NEWROW = $this->search->createElement("tr");
            $TBODY->appendChild($NEWROW);
            
            $NEWCELL = $this->search->createElement("td","Book ".$key);
            $NEWCELL->setAttribute("rowspan",25);
            $NEWROW->appendChild($NEWCELL);
        
            foreach($value as $langKey => $langValue){
                $NEWCELL = $this->search->createElement("td",$names[$langKey+1]);
                $NEWROW->appendChild($NEWCELL);
                
                $NEWCELL = $this->search->createElement("td",json_encode($langValue, JSON_UNESCAPED_UNICODE));
                $NEWROW->appendChild($NEWCELL);
                
                if($langKey < 24){
                  $NEWROW  = $this->search->createElement("tr");
                  $TBODY->appendChild($NEWROW);
                }
            }
        }
      } 
      */
      $this->outputResult();
      
    }


    private function getValidVersions(){
      
      $validversions = array();
      if($result = $this->mysqli->query("SELECT * FROM versions_available")){
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
    
    private function outputResult(){
      
      if($this->returntype == "json"){
        //print_r($metadata->results[52]); 
        echo json_encode($this->search, JSON_UNESCAPED_UNICODE);
      }
      else if($this->returntype == "xml"){
        echo $this->search->asXML();
      }
      else if($this->returntype == "html"){
        $this->search->appendChild($this->err); 
        $this->search->appendChild($this->div);
        echo $this->search->saveHTML($this->div); 
        echo $this->search->saveHTML($this->err);   
      }
      
      $this->mysqli->close();
      exit(0);  
    }

    
}

/*****************************************
 *      END BIBLEGET SEARCH CLASS        *
 ****************************************/

switch(strtoupper($_SERVER["REQUEST_METHOD"])) {
    case 'POST':
        //echo "A post request was detected...".PHP_EOL;
        //echo json_encode($_POST);
        $SEARCH = new BIBLEGET_METADATA($_POST);
        $SEARCH->Init();
        break;
    case 'GET':
        $SEARCH = new BIBLEGET_METADATA($_GET);
        $SEARCH->Init();
        break;
    default:
        exit(0);
        break;
}

?>