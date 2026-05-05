<?php

/**
 * BibleGet I/O Project Service Endpoint
 * listens on both GET requests and POST requests
 * whether ajax or not
 * accepts all cross-domain requests
 * is CORS enabled ( as far as I understand it )
 * 
 * ENDPOINT URL:    https://query.bibleget.io/v3/
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

//ini_set( 'display_errors', 1 );
//ini_set( 'display_startup_errors', 1 );
//error_reporting( E_ALL );

include 'includes/BibleGetQuote.php';
include 'includes/QueryValidator.php';
include 'includes/QueryFormulator.php';
include 'includes/QueryExecutor.php';

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

if( isset( $_SERVER['CONTENT_TYPE'] ) && !in_array( $_SERVER['CONTENT_TYPE'], BIBLEGET_QUOTE::ALLOWED_CONTENT_TYPES ) ){
    header( $_SERVER["SERVER_PROTOCOL"]." 415 Unsupported Media Type", true, 415 );
    die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ',BIBLEGET_QUOTE::ALLOWED_CONTENT_TYPES ).', but your Content Type was '.$_SERVER['CONTENT_TYPE'].'"}' );
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
          die( '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are '.implode( ' and ',BIBLEGET_QUOTE::ALLOWED_REQUEST_METHODS ).', but your Request Method was '.strtoupper( $_SERVER['REQUEST_METHOD'] ).'"}' );
  }
}
