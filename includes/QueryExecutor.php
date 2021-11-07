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

class QUERY_EXECUTOR {

    private BIBLEGET_QUOTE $BBQUOTE;
    private array $sqlqueries       = [];
    private array $queriesversions  = [];
    private array $currentRow       = [];
    private array $currentResponse  = [];
    private string $appid           = "";
    private string $domain          = "";
    private string $pluginversion   = "";
    private string $ipaddress       = "";
    private string $forwardedip     = "";
    private string $remote_address  = "";
    private string $realip          = "";
    private string $clientip        = "";
    private string $xquery          = "";
    private string $curYEAR         = "";
    private string $geoip_json      = "";
    private int $i                  = 0;
    private bool $haveIPAddressOnRecord = false;
    private mysqli_result $currentExecutionResult;

    // First we initialize some variables and flags with default values
    private string $version         = "";
    private string $book            = "";
    private int $chapter            = 0;
    private string $verse           = "";
    private bool $newversion        = false;
    private bool $newbook           = false;
    private bool $newchapter        = false;
    private bool $newverse          = false;

    function __construct( BIBLEGET_QUOTE $BBQUOTE ) {
        $this->BBQUOTE              = $BBQUOTE;
        $this->sqlqueries           = $BBQUOTE->formulatedQueries;
        $this->queriesversions      = $BBQUOTE->formulatedVariants;
        $this->appid                = $BBQUOTE->DATA["appid"]          != "" ? $BBQUOTE->DATA["appid"]            : "unknown";
        $this->domain               = $BBQUOTE->DATA["domain"]         != "" ? $BBQUOTE->DATA["domain"]           : "unknown";
        $this->pluginversion        = $BBQUOTE->DATA["pluginversion"]  != "" ? $BBQUOTE->DATA["pluginversion"]    : "unknown";
        $this->curYEAR              = date( 'Y' );
    }

    static private function validateIPAddress( string $ipaddress ) : string|bool {
        return filter_var( $ipaddress, FILTER_VALIDATE_IP );
    }

    private function getAndValidateIpAddress() {
        $this->forwardedip = isset( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ? $_SERVER["HTTP_X_FORWARDED_FOR"] : "";
        $this->remote_address = isset( $_SERVER["REMOTE_ADDR"] ) ? $_SERVER["REMOTE_ADDR"] : "";
        $this->realip = isset( $_SERVER["HTTP_X_REAL_IP"] ) ? $_SERVER["HTTP_X_REAL_IP"] : "";
        $this->clientip = isset( $_SERVER["HTTP_CLIENT_IP"] ) ? $_SERVER["HTTP_CLIENT_IP"] : "";

        //Do our best to identify an IP address associated with the incoming request, 
        //trying first HTTP_X_FORWARDED_FOR, then REMOTE_ADDR and last resort HTTP_X_REAL_IP
        //This is useful only to protect against high volume requests from specific IP addresses or referers
        $this->ipaddress = $this->forwardedip != "" ? explode( ",", $this->forwardedip )[0] : "";
        if ( $this->ipaddress == "" ) {
            $this->ipaddress = $this->remote_address != "" ? $this->remote_address : "";
        }
        if ( $this->ipaddress == "" ) {
            $this->ipaddress = $this->realip != "" ? $this->realip : "";
        }

        if ( self::validateIPAddress( $this->ipaddress ) === false ) {
            $this->BBQUOTE->addErrorMessage( "The BibleGet API endpoint cannot be used behind a proxy that hides the IP address from which the request is coming. No personal or sensitive data is collected by the API, however IP addresses are monitored to prevent spam requests. If you believe there is an error because this is not the case, please contact the developers so they can look into the situtation.", $this->xquery );
            $this->BBQUOTE->outputResult();
        }
    }

    private function isWhitelisted( string $domainOrIP ) : int|bool {
        return array_search( $domainOrIP, $this->BBQUOTE->WhitelistedDomainsIPs );
    }

    private function checkIPAddressPastTwoDaysWithSameRequest() {
        $ipresult = $this->ipaddress != "" ? $this->BBQUOTE->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $this->ipaddress . "' ) AND QUERY = '" . $this->xquery . "'  AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY )" ) : false;
        if ( $ipresult ) {
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have seen the IP Address [" . $this->ipaddress . "] in the past 2 days with this same request [" . $this->xquery . "]" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            //if more than 10 times in the past two days ( but less than 30 ) simply add message inviting to use cacheing mechanism
            if ( $ipresult->num_rows > 10 && $ipresult->num_rows < 30 ) {
                $this->BBQUOTE->addErrorMessage( 10, $this->xquery );
                $iprow = $ipresult->fetch_assoc();
                $this->geoip_json = $iprow[ "WHO_WHERE_JSON" ];
                $this->haveIPAddressOnRecord = true;
            }
            //if we have more than 30 requests in the past two days for the same query, deny service?
            else if ( $ipresult->num_rows > 29 ) {
                $this->BBQUOTE->addErrorMessage( 11, $xquery );
                $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
            }
        }

    }

    //and if the same IP address is making too many requests( >100? ) with different queries ( like copying the bible texts completely ), deny service
    private function checkQueriesFromSameIPAddress() {
        $ipresult = $this->ipaddress != "" ? $this->BBQUOTE->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $this->ipaddress . "' ) AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY )" ) : false;
        if ( $ipresult ) {
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have seen the IP Address [" . $this->ipaddress . "] in the past 2 days with many different requests" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            //if we 50 or more requests in the past two days, deny service?
            if ( $ipresult->num_rows > 100 ) {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have seen the IP Address [" . $this->ipaddress . "] in the past 2 days with over 50 requests", FILE_APPEND | LOCK_EX );
                }
                $this->BBQUOTE->addErrorMessage( 12, $this->xquery );
                $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
            }
        }
    }

    //let's add another check for "referer" websites and how many similar requests have derived from the same origin in the past couple days
    private function checkRequestsFromSameOrigin() {
        $originres = $this->BBQUOTE->mysqli->query( "SELECT ORIGIN,COUNT( * ) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE ORIGIN != '' AND ORIGIN = '" . $this->BBQUOTE->originHeader . "' AND QUERY = '" . $this->xquery . "' AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY ) GROUP BY ORIGIN" );
        if ( $originres ) {
            if ( $originres->num_rows > 0 ) {
                $originRow = $originres->fetch_assoc();
                if ( array_key_exists( "ORIGIN_CNT", $originRow ) ) {
                    if ( $originRow["ORIGIN_CNT"] > 10 && $originRow["ORIGIN_CNT"] < 30 ) {
                        $this->BBQUOTE->addErrorMessage( 10, $this->xquery );
                    } else if ( $originRow["ORIGIN_CNT"] > 29 ) {
                        $this->BBQUOTE->addErrorMessage( 11, $this->xquery );
                        $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
                    }
                }
            }
        }
    }

    //and we'll check for diverse requests from the same origin in the past couple days ( >100? )
    private function checkDiverseRequestsFromSameOrigin() {
        $originres = $this->BBQUOTE->mysqli->query( "SELECT ORIGIN,COUNT( * ) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE ORIGIN != '' AND ORIGIN = '" . $this->BBQUOTE->originHeader . "' AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY ) GROUP BY ORIGIN" );
        if ( $originres ) {
            if ( $originres->num_rows > 0 ) {
                $originRow = $originres->fetch_assoc();
                if ( array_key_exists( "ORIGIN_CNT", $originRow ) ) {
                    if ( $originRow["ORIGIN_CNT"] > 100 ) {
                        $this->BBQUOTE->addErrorMessage( 12, $this->xquery );
                        $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
                    }
                }
            }
        }
    }

    private function enforceQueryLimits() {

        $this->checkIPAddressPastTwoDaysWithSameRequest();

        $this->checkQueriesFromSameIPAddress();

        $this->checkRequestsFromSameOrigin();

        $this->checkDiverseRequestsFromSameOrigin();

    }

    private function getGeoIpInfo() {
        $ch = curl_init( "https://ipinfo.io/" . $this->ipaddress . "?token=" . IPINFO_ACCESS_TOKEN );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $this->geoip_json = curl_exec( $ch );
        if ( $this->geoip_json === false ) {
            $this->BBQUOTE->mysqli->query( "INSERT INTO curl_error ( ERRNO,ERROR ) VALUES( " . curl_errno( $ch ) . ",'" . curl_error( $ch ) . "' )" );
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

    private function getGeoIPInfoFromLogsElseOnline() {

        $geoIPFromLogs = $this->getGeoIPFromLogs();
        if ( $geoIPFromLogs !== false ) {
            if ( $this->haveGeoIPResultsFromLogs( $geoIPFromLogs ) ) {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->DEBUGFILE, "We already have valid geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "], reusing" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $iprow = $geoIPFromLogs->fetch_assoc();
                $this->geoip_json = $iprow["WHO_WHERE_JSON"];
                $this->haveIPAddressOnRecord = true;
            } else {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "We do not yet have valid geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "], nothing to reuse" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $this->getGeoIpInfo();
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have attempted to get geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
            }
        } else if ( $this->ipaddress != "" ) {
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "We do however seem to have a valid IP address [" . $this->ipaddress . "] , now trying to fetch info from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            $this->getGeoIpInfo();
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "Even in this case we have attempted to get geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
        }

    }

    private function normalizeErroredGeoIPInfo() {
        if ( $this->geoip_json === "" || $this->geoip_json === null ) {
            $this->geoip_json = '{"ERROR":""}';
        }
    }

    private function logQuery() {
        $stmt = $this->BBQUOTE->mysqli->prepare( "INSERT INTO requests_log__" . $this->curYEAR . " ( WHO_IP,WHO_WHERE_JSON,HEADERS_JSON,ORIGIN,QUERY,ORIGINALQUERY,REQUEST_METHOD,HTTP_CLIENT_IP,HTTP_X_FORWARDED_FOR,HTTP_X_REAL_IP,REMOTE_ADDR,APP_ID,DOMAIN,PLUGINVERSION ) VALUES ( INET6_ATON( ? ), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )" );
        $stmt->bind_param( 'ssssssssssssss', $this->ipaddress, $this->geoip_json, $this->BBQUOTE->jsonEncodedRequestHeaders, $this->BBQUOTE->originHeader, $this->xquery, $this->BBQUOTE->originalQueries[$this->i], $this->BBQUOTE->requestMethod, $this->clientip, $this->forwardedip, $this->realip, $this->remote_address, $this->appid, $this->domain, $this->pluginversion );
        if ( $stmt->execute() === false ) {
            $this->BBQUOTE->addErrorMessage( "There has been an error updating the logs: ( " . $this->BBQUOTE->mysqli->errno . " ) " . $this->BBQUOTE->mysqli->error );
        }
        $stmt->close();
    }


    private function geoIPInfoIsEmptyOrIsError() : bool|int {
        $pregmatch = preg_quote( '{"ERROR":"', '/' );
        return $this->haveIPAddressOnRecord === false || $this->geoip_json == "" || $this->geoip_json === null || preg_match( "/" . $pregmatch . "/", $this->geoip_json );
    }

    private function getGeoIPFromLogs() : mysqli_result|bool {
        if( $this->ipaddress != "" ){
            return $this->BBQUOTE->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $this->ipaddress . "' ) AND WHO_WHERE_JSON NOT LIKE '{\"ERROR\":\"%\"}'" );
        } else {
            return false;
        }
    }

    private function haveGeoIPResultsFromLogs( $geoIPFromLogs ) : bool {
        return $geoIPFromLogs->num_rows > 0;
    }

    private function fillIPAddressIfEmpty() {
        $this->ipaddress = $this->ipaddress != "" ? $this->ipaddress : "0.0.0.0";
    }

    private function prepareResponse() {
        $currentVariant = $this->queriesversions[$this->i];
        $row = $this->currentRow;

        $row["version"]             = strtoupper( $currentVariant );
        $row["testament"]           = ( int )$row["testament"];

        $universal_booknum          = $row["book"];
        $booknum                    = array_search( $row["book"], $this->BBQUOTE->INDEXES[$currentVariant]["book_num"] );
        $row["bookabbrev"]          = $this->BBQUOTE->INDEXES[$currentVariant]["abbreviations"][$booknum];
        $row["booknum"]             = $booknum;
        $row["univbooknum"]         = $universal_booknum;
        $row["book"]                = $this->BBQUOTE->INDEXES[$currentVariant]["biblebooks"][$booknum];

        $row["section"]             = ( int ) $row["section"];
        unset( $row["verseID"] );
        $row["chapter"]             = ( int ) $row["chapter"];
        $row["originalquery"]       = $this->BBQUOTE->originalQueries[$this->i];

        $this->currentResponse = $row;
    }

    private function generateResponse() {

        $response = $this->currentResponse;

        if ( $this->BBQUOTE->responseContentType == "xml" ) {

            $thisrow = $this->BBQUOTE->bibleQuote->results->addChild( "result" );
            foreach ( $response as $key => $value ) {
                $thisrow[$key] = $value;
            }

        } elseif ( $this->BBQUOTE->responseContentType == "json" ) {

            $this->BBQUOTE->bibleQuote->results[] = $response;

        } elseif ( $this->BBQUOTE->responseContentType == "html" ) {

            if ( $response["verse"] != $this->verse ) {
                $this->newverse = true;
                $this->verse = $response["verse"];
            } else {
                $this->newverse = false;
            }

            if ( $response["chapter"] != $this->chapter ) {
                $this->newchapter = true;
                $this->newverse = true;
                $this->chapter = $response["chapter"];
            } else {
                $this->newchapter = false;
            }

            if ( $response["book"] != $this->book ) {
                $this->newbook = true;
                $this->newchapter = true;
                $this->newverse = true;
                $this->book = $response["book"];
            } else {
                $this->newbook = false;
            }

            if ( $response["version"] != $this->version ) {
                $this->newversion = true;
                $this->newbook = true;
                $this->newchapter = true;
                $this->newverse = true;
                $this->version = $response["version"];
            } else {
                $this->newversion = false;
            }

            if ( $this->newversion ) {
                $variant = $this->BBQUOTE->bibleQuote->createElement( "p", $response["version"] );
                if ( $this->i > 0 ) {
                    $br = $this->BBQUOTE->bibleQuote->createElement( "br" );
                    $variant->insertBefore( $br, $variant->firstChild );
                }
                $variant->setAttribute( "class", "version bibleVersion" );
                $this->BBQUOTE->div->appendChild( $variant );
            }

            if ( $this->newbook || $this->newchapter ) {
                $citation = $this->BBQUOTE->bibleQuote->createElement( "p", $response["book"] . "&nbsp;" . $response["chapter"] );
                $citation->setAttribute( "class", "book bookChapter" );
                $this->BBQUOTE->div->appendChild( $citation );
                $citation1 = $this->BBQUOTE->bibleQuote->createElement( "p" );
                $citation1->setAttribute( "class", "verses versesParagraph" );
                $this->BBQUOTE->div->appendChild( $citation1 );
                $metainfo = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo->setAttribute( "type", "hidden" );
                $metainfo->setAttribute( "class", "originalQueries" );
                $metainfo->setAttribute( "value", $response["originalquery"] );
                $this->BBQUOTE->div->appendChild( $metainfo );
                $metainfo1 = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo1->setAttribute( "type", "hidden" );
                $metainfo1->setAttribute( "class", "bookAbbrev" );
                $metainfo1->setAttribute( "value", $response["bookabbrev"] );
                $this->BBQUOTE->div->appendChild( $metainfo1 );
                $metainfo2 = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo2->setAttribute( "type", "hidden" );
                $metainfo2->setAttribute( "class", "bookNum" );
                $metainfo2->setAttribute( "value", $response["booknum"] );
                $this->BBQUOTE->div->appendChild( $metainfo2 );
                $metainfo3 = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo3->setAttribute( "type", "hidden" );
                $metainfo3->setAttribute( "class", "univBookNum" );
                $metainfo3->setAttribute( "value", $response["univbooknum"] );
                $this->BBQUOTE->div->appendChild( $metainfo3 );
            }
            if ( $this->newverse ) {
                $versicle = $this->BBQUOTE->bibleQuote->createElement( "span", $response["verse"] );
                $versicle->setAttribute( "class", "sup verseNum" );
                $citation1->appendChild( $versicle );
            }

            $text = $this->BBQUOTE->bibleQuote->createElement( "span", $response["text"] );
            $text->setAttribute( "class", "text verseText" );
            $citation1->appendChild( $text );

        }
    }


    private function handleCurrentExecutionResult() {
        if ( $this->currentExecutionResult !== false ) {

            $this->BBQUOTE->incrementGoodQueryCount();

            if ( $this->geoIPInfoIsEmptyOrIsError() ) {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "Either we have not yet seen the IP address [" . $this->ipaddress . "] in the past 2 days or we have no geo_ip info [" . $this->geoip_json. "]" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $this->getGeoIPInfoFromLogsElseOnline();
            }

            $this->fillIPAddressIfEmpty();

            $this->normalizeErroredGeoIPInfo();

            $this->logQuery();

            $this->verse = "";
            $this->newverse = false;
            while ( $row = $this->currentExecutionResult->fetch_assoc() ) {

                $this->currentRow = $row;
                $this->prepareResponse();
                $this->generateResponse();

            }

        } else {
            $this->BBQUOTE->addErrorMessage( 9, $this->xquery );
        }
    }

    public function ExecuteSQLQueries() {
        $this->getAndValidateIpAddress();

        $notWhitelisted = ( $this->isWhitelisted( $this->domain ) === false && $this->isWhitelisted( $this->ipaddress ) === false );

        foreach ( $this->sqlqueries as $xquery ) {

            $this->xquery = $xquery;

            if ( $notWhitelisted ) {
                $this->enforceQueryLimits();
            }

            $this->currentExecutionResult = $this->BBQUOTE->mysqli->query( $xquery );
            $this->handleCurrentExecutionResult();
            $this->i++;
        }

    }
}
