<?php

/**
 * BibleGet I/O Project Service Endpoint for Metadata
 * listens on both GET requests and POST requests
 * whether ajax or not
 * accepts all cross-domain requests
 * is CORS enabled (as far as I understand it)
 * 
 * ENDPOINT URL:    https://query.bibleget.io/v3/metadata.php
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

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

/******************************************
 * START BUILDING BIBLEGET METADATA CLASS * 
 *****************************************/

class BIBLEGET_METADATA {

  static public array $returntypes = ["json", "xml", "html"];
  static public array $allowed_accept_headers = ["application/json", "application/xml", "text/html"];
  static public array $allowed_content_types = ["application/json", "application/x-www-form-urlencoded"];
  static public array $allowed_request_methods = ["GET", "POST"];

  private array $DATA;
  private array $requestHeaders;
  private string $returntype;
  private string $acceptHeader;
  private mysqli $mysqli;
  private array $validversions;
  //private bool $is_ajax;
  private stdClass|simpleXMLElement|DOMDocument $metadata;
  private DOMElement $div;
  private DOMElement $err;
  private DOMElement $inf;

  function __construct($DATA) {
    $this->requestHeaders = getallheaders();
    $this->DATA = $DATA;
    $this->contenttype = isset($_SERVER['CONTENT_TYPE']) && in_array($_SERVER['CONTENT_TYPE'], self::$allowed_content_types) ? $_SERVER['CONTENT_TYPE'] : NULL;
    $this->acceptHeader = isset($this->requestHeaders["Accept"]) && in_array($this->requestHeaders["Accept"], self::$allowed_accept_headers) ? self::$returntypes[array_search($this->requestHeaders["Accept"], self::$allowed_accept_headers)] : "";
    $this->returntype = (isset($DATA["return"]) && in_array(strtolower($DATA["return"]), self::$returntypes)) ? strtolower($DATA["return"]) : ($this->acceptHeader !== "" ? $this->acceptHeader : self::$returntypes[0]);
    //$this->isAjax = ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' );
  }

  public function Init() {
    switch ($this->returntype) {
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

    $this->MetaDataInit();

    $this->dbConnect();

    if (isset($this->DATA["query"]) && $this->DATA["query"] != "") {
      switch ($this->DATA["query"]) {
        case "biblebooks":
          $this->getBibleBooks();
          break;
        case "bibleversions":
          $this->getBibleVersions("BIBLE");
          break;
        case "literatureversions":
          $this->getBibleVersions("LITERATURE");
          break;
        case "versionindex":
          $this->validversions = $this->getValidVersions();
          $this->getVersionIndex();
          break;
        default:
          exit(0);
      }
    }
  }

  private function dbConnect() {

    define("BIBLEGETIOQUERYSCRIPT", "iknowwhythisishere");

    $dbCredentials = "dbcredentials.php";
    //search for the database credentials file at least three levels up...
    if (file_exists($dbCredentials)) {
      include $dbCredentials;
    } else if (file_exists("../" . $dbCredentials)) {
      include "../{$dbCredentials}";
    } else if (file_exists("../../" . $dbCredentials)) {
      include "../../{$dbCredentials}";
    }

    $mysqli = new mysqli(SERVER, DBUSER, DBPASS, DATABASE);

    if ($mysqli->connect_errno) {
      $this->addErrorMessage("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
      $this->outputResult();
    }
    $mysqli->set_charset("utf8");
    /*
        if (!$mysqli->set_charset("utf8")) {
          //printf("Error loading character set utf8: %s\n", $mysqli->error);
        } else {
          //printf("Current character set: %s\n", $mysqli->character_set_name());
        }
        */
    $this->mysqli =  $mysqli;
  }


  static private function toProperCase($txt): string {
    preg_match("/\p{L}\p{M}*/u", $txt, $mList, PREG_OFFSET_CAPTURE);
    if (array_key_exists(0, $mList)) {
      $idx = $mList[0][1];
      $chr = mb_substr($txt, $idx, 1, 'UTF-8');
      if (preg_match("/\p{L&}\p{M}*/u", $chr)) {
        $post = mb_substr($txt, $idx + 1, null, 'UTF-8');
        return mb_substr($txt, 0, $idx, 'UTF-8') . mb_strtoupper($chr, 'UTF-8') . mb_strtolower($post, 'UTF-8');
      } else {
        return $txt;
      }
    } else {
      return $txt;
    }
  }


  private function MetaDataInit() {

    switch ($this->returntype) {

      case "json":
        $metadata = new stdClass();
        $metadata->results = array();
        $metadata->errors = array();
        $metadata->info = array();
        break;
      case "xml":
        $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . "><BibleGetMetadata/>";
        $metadata = new simpleXMLElement($root);
        $metadata->addChild("errors");
        $metadata->addChild("info");
        break;
      case "html":
        $metadata = new DOMDocument();
        $html = "<!DOCTYPE HTML><head><title>BibleGet Query Result</title></head><body></body>";
        $metadata->loadHTML($html);
        $div = $metadata->createElement("div");
        $div->setAttribute("class", "results bibleMetadata");
        $err = $metadata->createElement("div");
        $err->setAttribute("class", "errors bibleMetadata");
        $inf = $metadata->createElement("div");
        $inf->setAttribute("class", "info bibleMetadata");
        $this->div        = $div;
        $this->err        = $err;
        $this->inf        = $inf;
        break;
    }

    $this->metadata = $metadata;
  }


  private function addErrorMessage($str) {

    switch ($this->returntype) {
      case "json":
        $error = array();
        $error["errMessage"] = $str;
        $this->metadata->errors[] = $error;
        break;
      case "xml":
        $err_row = $this->metadata->errors->addChild("error", $str);
        break;
      case "html":
        $elements = array();

        $elements[0] = $this->metadata->createElement("table");
        $elements[0]->setAttribute("id", "errorsTbl");
        $elements[0]->setAttribute("class", "errorsTbl");
        $this->err->appendChild($elements[0]);

        $elements[1] = $this->metadata->createElement("tr");
        $elements[1]->setAttribute("id", "errorsRow1");
        $elements[1]->setAttribute("class", "errorsRow1");
        $elements[0]->appendChild($elements[1]);

        $elements[2] = $this->metadata->createElement("td", "errMessage");
        $elements[2]->setAttribute("class", "errMessage");
        $elements[2]->setAttribute("id", "errMessage");
        $elements[1]->appendChild($elements[2]);

        $elements[3] = $this->metadata->createElement("td", $str);
        $elements[3]->setAttribute("class", "errMessageVal");
        $elements[3]->setAttribute("id", "errMessageVal");
        $elements[1]->appendChild($elements[3]);
        break;
    }
  }


  private function getBibleBooks() {

    // PREPARE BIBLEBOOKS ARRAY
    $biblebooks = array();
    $result1 = $this->mysqli->query("SELECT * FROM biblebooks_fullname");
    if ($result1) {
      $cols = mysqli_num_fields($result1);
      $names = array();
      $finfo = mysqli_fetch_fields($result1);
      foreach ($finfo as $val) {
        $names[] = $val->name;
      }
      $result2 = $this->mysqli->query("SELECT * FROM biblebooks_abbr");
      if ($result2) {
        $cols2 = mysqli_num_fields($result2);
        $rows2 = mysqli_num_rows($result2);
        $names2 = array();
        $finfo2 = mysqli_fetch_fields($result2);
        foreach ($finfo2 as $val) {
          $names2[] = $val->name;
        }

        $n = 0;
        while ($row1 = mysqli_fetch_assoc($result1)) {
          $row2 = mysqli_fetch_assoc($result2);
          $biblebooks[$n] = array();

          for ($x = 0; $x < $cols - 1; $x++) {
            $biblebooks[$n][$x] = array();
            $temparray = array();
            $temparray[0] = $row1[$names[$x + 1]];
            $temparray[1] = $row2[$names[$x + 1]];

            $arr1 = explode(" | ", $row1[$names[$x + 1]]);
            $booknames = array_map(function ($str) {
              $str = trim($str);
              $str = preg_replace("/\s+/", "", $str);
              return self::toProperCase($str);
            }, $arr1);

            $arr2 = explode(" | ", $row2[$names[$x + 1]]);
            $abbrevs = (count($arr2) > 1) ? array_map(function ($str) {
              $str = trim($str);
              $str = preg_replace("/\s+/", "", $str);
              return self::toProperCase($str);
            }, $arr2) : array();
            //if($n==52){ echo "<p>TEMPARRAY</p><pre>"; print_r($temparray); echo "</pre>"; echo "<p>BOOKNAMES</p><pre>"; print_r($booknames); echo "</pre>"; echo "<p>ABBREVS</p><pre>"; print_r($abbrevs); echo "</pre>"; }
            $biblebooks[$n][$x] = array_merge($temparray, $booknames, $abbrevs);
            //if($n==52){ echo "<p>BIBLEBOOKS</p><pre>"; print_r($biblebooks[$n][$x]); echo "</pre>"; }
          }
          $n++;
        }
      } else {
        $this->addErrorMessage("<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>");
        $this->outputResult();
      }
    } else {
      $this->addErrorMessage("<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>");
      $this->outputResult();
    }

    switch ($this->returntype) {
      case "json":
        $this->metadata->results = $biblebooks;
        $z = array_shift($names);
        $this->metadata->languages = $names;
        //$this->metadata->results->biblebooks = $biblebooks;
        break;
      case "xml":
        foreach ($biblebooks as $key => $value) {
          $book = $this->metadata->addChild("Book");
          $book->addAttribute("key", $key);
          foreach ($value as $langKey => $langValue) {
            $bookname = json_encode($langValue, JSON_UNESCAPED_UNICODE);
            $book->addChild($names[$langKey + 1], $bookname);
          }
        }
        break;
      case "html":
        $TABLE = $this->metadata->createElement("table");
        $TABLE->setAttribute("id", "BibleBooksTbl");
        $TABLE->setAttribute("class", "BibleBooksTbl");
        $this->div->appendChild($TABLE);

        $THEAD = $this->metadata->createElement("thead");
        $TABLE->appendChild($THEAD);

        $NEWROW = $this->metadata->createElement("tr");
        $THEAD->appendChild($NEWROW);

        $NEWCOL = array();
        $NEWCOL["BOOKIDX"] = $this->metadata->createElement("td", "BOOK INDEX");
        $NEWROW->appendChild($NEWCOL["BOOKIDX"]);
        $NEWCOL["LANGUAGE"] = $this->metadata->createElement("td", "LANGUAGE");
        $NEWROW->appendChild($NEWCOL["LANGUAGE"]);
        $NEWCOL["VALUE"] = $this->metadata->createElement("td", "VALUE");
        $NEWROW->appendChild($NEWCOL["VALUE"]);

        $TBODY = $this->metadata->createElement("tbody");
        $TABLE->appendChild($TBODY);

        foreach ($biblebooks as $key => $value) {
          $NEWROW = $this->metadata->createElement("tr");
          $TBODY->appendChild($NEWROW);

          $NEWCELL = $this->metadata->createElement("td", "Book " . $key);
          $NEWCELL->setAttribute("rowspan", 25);
          $NEWROW->appendChild($NEWCELL);

          foreach ($value as $langKey => $langValue) {
            $NEWCELL = $this->metadata->createElement("td", $names[$langKey + 1]);
            $NEWROW->appendChild($NEWCELL);

            $NEWCELL = $this->metadata->createElement("td", json_encode($langValue, JSON_UNESCAPED_UNICODE));
            $NEWROW->appendChild($NEWCELL);

            if ($langKey < 24) {
              $NEWROW  = $this->metadata->createElement("tr");
              $TBODY->appendChild($NEWROW);
            }
          }
        }
        break;
    }

    $this->outputResult();
  }


  private function getBibleVersions($type = "") {

    // PREPARE VALIDVERSIONS ARRAY
    switch ($this->returntype) {
      case "json":
        $this->metadata->validversions = array();
        $this->metadata->validversions_fullname = array();
        $this->metadata->copyrightversions = array();
        break;
      case "xml":
        $this->metadata->addChild("ValidVersions");
        $this->metadata->addChild("ValidVersionsFullname");
        $this->metadata->addChild("CopyrightVersions");
        break;
      case "html":
        $TABLE = $this->metadata->createElement("table");
        $TABLE->setAttribute("id", "BibleVersionsTbl");
        $TABLE->setAttribute("class", "BibleVersionsTbl");
        $this->div->appendChild($TABLE);

        $THEAD = $this->metadata->createElement("thead");
        $TABLE->appendChild($THEAD);

        $NEWROW = $this->metadata->createElement("tr");
        $THEAD->appendChild($NEWROW);

        $NEWCOL = array();
        $NEWCOL["ABBREVIATION"] = $this->metadata->createElement("th", "ABBREVIATION");
        $NEWROW->appendChild($NEWCOL["ABBREVIATION"]);
        $NEWCOL["FULLNAME"] = $this->metadata->createElement("th", "FULLNAME");
        $NEWROW->appendChild($NEWCOL["FULLNAME"]);
        $NEWCOL["YEAR"] = $this->metadata->createElement("th", "YEAR");
        $NEWROW->appendChild($NEWCOL["YEAR"]);
        $NEWCOL["LANGUAGE"] = $this->metadata->createElement("th", "LANGUAGE");
        $NEWROW->appendChild($NEWCOL["LANGUAGE"]);
        $NEWCOL["COPYRIGHT"] = $this->metadata->createElement("th", "COPYRIGHT");
        $NEWROW->appendChild($NEWCOL["COPYRIGHT"]);
        $NEWCOL["COPYRIGHT_HOLDER"] = $this->metadata->createElement("th", "COPYRIGHT_HOLDER");
        $NEWROW->appendChild($NEWCOL["COPYRIGHT_HOLDER"]);
        $NEWCOL["IMPRIMATUR"] = $this->metadata->createElement("th", "IMPRIMATUR");
        $NEWROW->appendChild($NEWCOL["IMPRIMATUR"]);
        $NEWCOL["CANON"] = $this->metadata->createElement("th", "CANON");
        $NEWROW->appendChild($NEWCOL["CANON"]);

        $TBODY = $this->metadata->createElement("tbody");
        $TABLE->appendChild($TBODY);
        break;
    }

    $querystring = "SELECT * FROM versions_available";
    if ($type !== "") {
      $querystring .= " WHERE type='$type'";
    }
    $result = $this->mysqli->query($querystring);
    if ($result) {
      $n = 0;
      while ($row = mysqli_fetch_assoc($result)) {
        switch ($this->returntype) {
          case "json":
            $output_info_array = [
              $row["fullname"],
              $row["year"],
              $row["language"],
              $row["imprimatur"],
              $row["canon"],
              $row["copyright_holder"],
              $row["notes"]
            ];
            $this->metadata->validversions_fullname[$row["sigla"]] = implode("|", $output_info_array);
            $this->metadata->validversions[] = $row["sigla"];
            if ($row["copyright"] == 1) {
              $this->metadata->copyrightversions[] = $row["sigla"];
            }
            break;
          case "xml":
            $this->metadata->ValidVersions->addChild($row["sigla"]);
            $versionFullName = $this->metadata->ValidVersionsFullname->addChild($row["sigla"]);
            $versionFullName->addChild("Fullname",         $row["fullname"]);
            $versionFullName->addChild("Year",             $row["year"]);
            $versionFullName->addChild("Language",         $row["language"]);
            $versionFullName->addChild("Imprimatur",       $row["imprimatur"]);
            $versionFullName->addChild("Canon",            $row["canon"]);
            $versionFullName->addChild("CopyrightHolder",  $row["copyright_holder"]);
            $versionFullName->addChild("Notes",            htmlspecialchars($row["notes"], ENT_XML1, 'UTF-8'));
            if ($row["copyright"] == 1) {
              $this->metadata->CopyrightVersions->addChild($row["sigla"]);
            }
            break;
          case "html":
            $n++;
            $NEWROW = $this->metadata->createElement("tr");
            $NEWROW->setAttribute("id", "ValidVersionRow" . $n);
            $NEWROW->setAttribute("class", "ValidVersionRow");
            $TBODY->appendChild($NEWROW);

            $NEWCELL = array();

            $NEWCELL["ABBREVIATION"] = $this->metadata->createElement("td", $row["sigla"]);
            $NEWROW->appendChild($NEWCELL["ABBREVIATION"]);

            $NEWCELL["FULLNAME"] = $this->metadata->createElement("td", $row["fullname"]);
            $NEWROW->appendChild($NEWCELL["FULLNAME"]);

            $NEWCELL["YEAR"] = $this->metadata->createElement("td", $row["year"]);
            $NEWROW->appendChild($NEWCELL["YEAR"]);

            $NEWCELL["LANGUAGE"] = $this->metadata->createElement("td", $row["language"]);
            $NEWROW->appendChild($NEWCELL["LANGUAGE"]);

            $NEWCELL["COPYRIGHT"] = $this->metadata->createElement("td", $row["copyright"]);
            $NEWROW->appendChild($NEWCELL["COPYRIGHT"]);

            $NEWCELL["COPYRIGHT_HOLDER"] = $this->metadata->createElement("td", $row["copyright_holder"]);
            $NEWROW->appendChild($NEWCELL["COPYRIGHT_HOLDER"]);

            $NEWCELL["IMPRIMATUR"] = $this->metadata->createElement("td", $row["imprimatur"]);
            $NEWROW->appendChild($NEWCELL["IMPRIMATUR"]);

            $NEWCELL["CANON"] = $this->metadata->createElement("td", $row["canon"]);
            $NEWROW->appendChild($NEWCELL["CANON"]);
            break;
        }
      }
    } else {
      $this->addErrorMessage("<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>");
    }

    $this->outputResult();
  }

  private function getValidVersions() {

    $validversions = array();
    $result = $this->mysqli->query("SELECT * FROM versions_available");
    if ($result) {
      while ($row = mysqli_fetch_assoc($result)) {
        $validversions[] = $row["sigla"];
      }
    } else {
      $this->addErrorMessage("<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>");
      $this->outputResult();
    }
    return $validversions;
  }

  private function checkValidVersions($value) {
    return (in_array($value, $this->validversions));
  }

  private function getVersionIndex() {

    if (isset($this->DATA["versions"]) && $this->DATA["versions"] != "") {
      $versions = explode(",", $this->DATA["versions"]);
    }
    if (count($versions) > 0) {
      $versions = array_filter($versions, array($this, "checkValidVersions"));
      if (count($versions) > 0) {
        $indexes = array();
        foreach ($versions as $variant) {
          $abbreviations = array();
          $bbbooks = array();
          $chapter_limit = array();
          $verse_limit = array();
          $book_num = array();

          // fetch the index information for the requested version from the database and load it into our arrays
          $result = $this->mysqli->query("SELECT * FROM " . $variant . "_idx");
          if ($result) {
            while ($row = $result->fetch_assoc()) {
              $abbreviations[] = $row["abbrev"];
              $bbbooks[] = $row["fullname"];
              $chapter_limit[] = (int)$row["chapters"];
              $verse_limit[] = array_map('intval', explode(",", $row["verses_last"]));
              $book_num[] = (int)$row["book"];
            }
          } else {
            $this->addErrorMessage("<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>");
          }

          $indexes[$variant]["abbreviations"] = $abbreviations;
          $indexes[$variant]["biblebooks"] = $bbbooks;
          $indexes[$variant]["chapter_limit"] = $chapter_limit;
          $indexes[$variant]["verse_limit"] = $verse_limit;
          $indexes[$variant]["book_num"] = $book_num;
        }
        switch ($this->returntype) {
          case "json":
            $this->metadata->indexes = $indexes;
            break;
          case "xml":
            $this->metadata->addChild("Indexes");
            foreach ($indexes as $idxvariant => $idxvalue) {
              $xmlIndexEl = $this->metadata->Indexes->addChild($idxvariant);

              $abbrsEl = $xmlIndexEl->addChild("Abbreviations");
              $c = 0;
              foreach ($idxvalue["abbreviations"] as $abbr) {
                $abbrEl = $abbrsEl->addChild("Abbreviation", $abbr);
                $abbrEl["idx"] = $c++;
              }

              $bibleBooksEl = $xmlIndexEl->addChild("BibleBooks");
              $c = 0;
              foreach ($idxvalue["biblebooks"] as $bookName) {
                $bibleBookEl = $bibleBooksEl->addChild("BibleBook", $bookName);
                $bibleBookEl["idx"] = $c++;
              }

              $chapterLimsEl = $xmlIndexEl->addChild("ChapterLimits");
              $c = 0;
              foreach ($idxvalue["chapter_limit"] as $chapterLimit) {
                $chapterLimEl = $chapterLimsEl->addChild("ChapterLimit", $chapterLimit);
                $chapterLimEl["book"] = $idxvalue["biblebooks"][$c];
                $chapterLimEl["idx"] = $c++;
              }

              $verseLimitsEl = $xmlIndexEl->addChild("VerseLimits");
              $c = 0;
              foreach ($idxvalue["verse_limit"] as $verseLimit) {
                $verseLimEl = $verseLimitsEl->addChild("VerseLimit");
                $verseLimEl["book"] = $idxvalue["biblebooks"][$c];
                $verseLimEl["idx"] = $c++;
                $x = 0;
                foreach ($verseLimit as $perChapterVerseLimit) {
                  $chapterEl = $verseLimEl->addChild("VerseLimitForChapter", $perChapterVerseLimit);
                  $chapterEl["chapter"] = ++$x;
                }
              }
              $c = 0;
              $bookNumsEl = $xmlIndexEl->addChild("BookNums");
              foreach ($idxvalue["book_num"] as $bookNum) {
                $bookNumEl = $bookNumsEl->addChild("BookNum", $bookNum);
                $bookNumEl["book"] = $idxvalue["biblebooks"][$c++];
              }
            }
            break;
          case "html":
            $TABLE = $this->metadata->createElement("table");
            $TABLE->setAttribute("id", "VersionIndexesTbl");
            $TABLE->setAttribute("class", "VersionIndexesTbl");
            $this->div->appendChild($TABLE);

            $THEAD = $this->metadata->createElement("thead");
            $TABLE->appendChild($THEAD);

            $NEWROW = $this->metadata->createElement("tr");
            $THEAD->appendChild($NEWROW);

            $NEWCOL = array();
            $NEWCOL["VERSION"] = $this->metadata->createElement("td", "VERSION");
            $NEWROW->appendChild($NEWCOL["VERSION"]);

            $NEWCOL["BIBLEBOOKS"] = $this->metadata->createElement("td", "BIBLEBOOKS");
            $NEWROW->appendChild($NEWCOL["BIBLEBOOKS"]);

            $NEWCOL["ABBREVIATIONS"] = $this->metadata->createElement("td", "ABBREVIATIONS");
            $NEWROW->appendChild($NEWCOL["ABBREVIATIONS"]);

            $NEWCOL["CHAPTERLIMIT"] = $this->metadata->createElement("td", "CHAPTER_LIMIT");
            $NEWROW->appendChild($NEWCOL["CHAPTERLIMIT"]);

            $NEWCOL["VERSELIMIT"] = $this->metadata->createElement("td", "VERSE_LIMIT");
            $NEWROW->appendChild($NEWCOL["VERSELIMIT"]);

            $NEWCOL["BOOKNUM"] = $this->metadata->createElement("td", "BOOK_NUM");
            $NEWROW->appendChild($NEWCOL["BOOKNUM"]);

            $TBODY = $this->metadata->createElement("tbody");
            $TABLE->appendChild($TBODY);

            foreach ($indexes as $idxvariant => $idxvalue) {

              $NEWROW = $this->metadata->createElement("tr");
              $TBODY->appendChild($NEWROW);

              $NEWCELL = array();

              $NEWCELL["VERSION"] = $this->metadata->createElement("td", $idxvariant);
              $NEWROW->appendChild($NEWCELL["VERSION"]);

              $NEWCELL["BIBLEBOOKS"] = $this->metadata->createElement("td", json_encode($idxvalue["biblebooks"], JSON_UNESCAPED_UNICODE));
              $NEWROW->appendChild($NEWCELL["BIBLEBOOKS"]);

              $NEWCELL["ABBREVIATIONS"] = $this->metadata->createElement("td", json_encode($idxvalue["abbreviations"], JSON_UNESCAPED_UNICODE));
              $NEWROW->appendChild($NEWCELL["ABBREVIATIONS"]);

              $NEWCELL["CHAPTERLIMIT"] = $this->metadata->createElement("td", json_encode($idxvalue["chapter_limit"]));
              $NEWROW->appendChild($NEWCELL["CHAPTERLIMIT"]);

              $NEWCELL["VERSELIMIT"] = $this->metadata->createElement("td", json_encode($idxvalue["verse_limit"]));
              $NEWROW->appendChild($NEWCELL["VERSELIMIT"]);

              $NEWCELL["BOOKNUM"] = $this->metadata->createElement("td", json_encode($idxvalue["book_num"]));
              $NEWROW->appendChild($NEWCELL["BOOKNUM"]);
            }
            break;
        }
      } else {
        $this->addErrorMessage("Sorry but there isn't a single valid version in this request.");
      }
    } else {
      $this->addErrorMessage("Sorry but there isn't a single version in this request.");
    }

    $this->outputResult();
  }


  private function outputResult() {

    switch ($this->returntype) {
      case "json":
        //print_r($metadata->results[52]);
        $this->metadata->info["ENDPOINT_VERSION"] = ENDPOINT_VERSION;
        echo json_encode($this->metadata, JSON_UNESCAPED_UNICODE);
        break;
      case "xml":
        $this->metadata->info->addAttribute("ENDPOINT_VERSION", ENDPOINT_VERSION);
        echo $this->metadata->asXML();
        break;
      case "html":
        $this->metadata->appendChild($this->err);
        $this->metadata->appendChild($this->div);

        $info = $this->metadata->createElement("input");
        $info->setAttribute("type", "hidden");
        $info->setAttribute("name", "ENDPOINT_VERSION");
        $info->setAttribute("value", ENDPOINT_VERSION);
        $this->inf->appendChild($info);
        $this->metadata->appendChild($this->inf);

        echo $this->metadata->saveHTML($this->div);
        echo $this->metadata->saveHTML($this->err);
        echo $this->metadata->saveHTML($this->inf);
        break;
    }

    $this->mysqli->close();
    exit(0);
  }
}

/*****************************************
 *     END BIBLEGET METADATA CLASS       * 
 ****************************************/
if (isset($_SERVER['CONTENT_TYPE']) && !in_array($_SERVER['CONTENT_TYPE'], BIBLEGET_METADATA::$allowed_content_types)) {
  header($_SERVER["SERVER_PROTOCOL"] . " 415 Unsupported Media Type", true, 415);
  die('{"error":"You seem to be forming a strange kind of request? Allowed Content Types are ' . implode(' and ', BIBLEGET_METADATA::$allowed_content_types) . ', but your Content Type was ' . $_SERVER['CONTENT_TYPE'] . '"}');
} else if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
  $json = file_get_contents('php://input');
  $data = json_decode($json, true);
  if (NULL === $json || "" === $json) {
    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request", true, 400);
    die('{"error":"No JSON data received in the request: <' . $json . '>"');
  } else if (json_last_error() !== JSON_ERROR_NONE) {
    header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request", true, 400);
    die('{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}');
  } else {
    $METADATA = new BIBLEGET_METADATA($data);
    $METADATA->Init();
  }
} else {
  switch (strtoupper($_SERVER["REQUEST_METHOD"])) {
    case 'POST':
      $METADATA = new BIBLEGET_METADATA($_POST);
      $METADATA->Init();
      break;
    case 'GET':
      $METADATA = new BIBLEGET_METADATA($_GET);
      $METADATA->Init();
      break;
    default:
      header($_SERVER["SERVER_PROTOCOL"] . " 405 Method Not Allowed", true, 405);
      die('{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are ' . implode(' and ', BIBLEGET_METADATA::$allowed_request_methods) . ', but your Request Method was ' . strtoupper($_SERVER['REQUEST_METHOD']) . '"}');
  }
}
