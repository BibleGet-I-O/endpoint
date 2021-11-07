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

class QUERY_FORMULATOR {

    private BIBLEGET_QUOTE $BBQUOTE;
    private int $nn                     = 0;
    private int $i                      = -1;
    private string $currentQuery        = "";
    private string $currentFullQuery    = "";
    private string $sqlQuery            = "";
    private string $previousBook        = "";
    private string $currentBook         = "";
    private string $currentVariant      = "";
    private string $currentPreferOrigin = "";
    public array $sqlQueries            = [];
    public array $queriesVersions       = [];
    public array $originalQueries       = [];
    public array $queries               = [];

    function __construct( BIBLEGET_QUOTE $BBQUOTE ) {
        $this->BBQUOTE = $BBQUOTE;
        $this->queries = $BBQUOTE->validatedQueries;
    }

    static private function queryContainsNonConsecutiveVerses( string $query ) : bool {
        return strpos( $query, "." ) !== false;
    }

    static private function getNonConsecutiveChunks( string $query ) : array {
        return preg_split( "/\./", $query );
    }

    static private function chunkContainsRange( string $chunk ) : bool {
        return strpos( $chunk, "-" ) !== false;
    }

    static private function chunkContainsChapterVerseConstruct( string $chunk ) : bool {
        return strpos( $chunk, "," ) !== false;
    }

    static private function getRange( string $chunk ) : array {
        [ $from, $to ] = preg_split( "/\-/", $chunk );
        return [
            'from'  => $from,
            'to'    => $to
        ];
    }

    static private function getChapterVerseFromConstruct( string $construct ) : array {
        [ $chapter, $verse ] = preg_split( "/,/", $construct );
        return [
            'chapter'   => $chapter,
            'verse'     => $verse
        ];
    }

    static private function forceArray( $element ) : array {
        if ( !is_array( $element ) ) {
            $element = [ $element ];
        }
        return $element;
    }

    private function captureBookIndicator() : array|bool {
        if( $this->BBQUOTE::stringWithUpperAndLowerCaseVariants( $this->currentQuery ) ){
            if( preg_match( "/^([1-4]{0,1}((\p{Lu}\p{Ll}*)+))/u", $this->currentQuery, $res ) ){
                $this->currentQuery = preg_replace( "/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", "", $this->currentQuery );
                return $res;
            } else {
                return false;
            }
        } else {
            if( preg_match( "/^([1-4]{0,1}((\p{L}\p{M}*)+))/u", $this->currentQuery, $res ) ){
                $this->currentQuery = preg_replace( "/^[1-4]{0,1}(\p{L}\p{M}*)+/u", "", $this->currentQuery );
                return $res;
            } else {
                return false;
            }
        }
    }

    private function validateVerseOriginPreference() {
        $this->currentPreferOrigin = "";
        //if we are dealing with a book that has greek and hebrew variants, we need to distinguish between the two
        if( $this->currentBook == 19 || $this->currentBook == "19" ){ 
            //if a protestant version is requested, it will only have HEBREW origin, not GREEK
            //if preferorigin is not explicitly set, but chapters 11-20 are requested for Esther,
            //then obviously the HEBREW version is being preferred, we will need to translate this request when we know which chapter we are dealing with
            if( in_array( $this->currentRequestedVariant,$this->BBQUOTE->CATHOLIC_VERSIONS ) ){
                $this->currentPreferOrigin = " AND verseorigin = '" . ( $this->BBQUOTE->DATA['preferorigin'] != "" ? $this->BBQUOTE->DATA['preferorigin'] : "GREEK" ) . "'";
            }
        }
    }

    private function initSQLStatement() {
        $this->sqlQuery = "SELECT * FROM " . $this->currentRequestedVariant . " WHERE book = " . $this->currentBook;
    }

    private function setSQLLimit() {
        if ( in_array( $this->currentRequestedVariant, $this->BBQUOTE->COPYRIGHT_VERSIONS ) ) {
            $this->sqlQueries[$this->nn] .= " LIMIT 30";
        }
    }

    private function finalizeQuery() {
        $this->sqlQueries[$this->nn] .= $this->currentPreferOrigin;
        $this->queriesVersions[$this->nn] = $this->currentRequestedVariant;
        //VERSES must be ordered by verseID in order to handle those cases where there are subverses ( usually Greek additions )
        //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
        //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
        //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
        //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
        $this->sqlQueries[$this->nn] .= " ORDER BY verseID";
        //$this->sqlQueries[$this->nn] .= " ORDER BY book,chapter,verse,verseequiv";
        $this->setSQLLimit();
    }

    private function bestGuessBookIdx( array $matchedBook ) : int {
        $key1 = $this->currentVariant != "" ? array_search( $matchedBook[0], $this->BBQUOTE->INDEXES[$this->currentVariant]["biblebooks"] ) : false;
        $key2 = $this->currentVariant != "" ? array_search( $matchedBook[0], $this->BBQUOTE->INDEXES[$this->currentVariant]["abbreviations"] ) : false;
        $key3 = $this->BBQUOTE::idxOf( $matchedBook[0], $this->BBQUOTE->BIBLEBOOKS );
        if ( $key1 ) {
            return $this->BBQUOTE->INDEXES[$this->currentVariant]["book_num"][$key1];
        } else if ( $key2 ) {
            return $this->BBQUOTE->INDEXES[$this->currentVariant]["book_num"][$key2];
        } else if ( $key3 ) {
            return $key3 + 1;
        }
    }

    private function setBookAndVariant( array|bool $matchedBook ) {
        if ( $matchedBook ) {
            $this->currentVariant = $this->BBQUOTE->validatedVariants[ $this->i ];
            $this->currentBook = $this->bestGuessBookIdx( $matchedBook );
            $this->previousBook = $this->currentBook;
        } else {
            $this->currentBook = $this->previousBook;
        }
    }

    private function accountForMultipleChapterDifference( $cvConstructLeft, $cvConstructRight ) {
        if( $cvConstructRight['chapter'] - $cvConstructLeft['chapter'] > 1 ) {
            for( $d=1;$d<( $cvConstructRight['chapter'] - $cvConstructLeft['chapter'] );$d++ ){
                $this->sqlQueries[$this->nn] .= " OR ( chapter = " . ( $cvConstructLeft['chapter'] + $d ) . " )";
            }
        }
    }

    private function mapReference( int|string|null $chapter, int|string|null $verse, int|string|null &$toChapter, int|string|null &$toVerse, bool $updatePreferOrigin ) {
        $version        = $this->currentRequestedVariant;
        $book           = $this->currentBook;
        $preferorigin   = $this->currentPreferOrigin;

        if( in_array( $version, $this->BBQUOTE->CATHOLIC_VERSIONS ) ){
            if( $book == 19 || $book == "19" ){ //19 == Esther
                //first let's make sure that $fromChapter is a number
                if( gettype( $fromChapter ) == 'string' ){
                    //the USCCB uses letters A-F to indicate the Greek additions to Esther
                    //however, the BibleGet engine does not allow chapters that are not numbers
                    //therefore these verses have been added to the database in the same fashion as the CEI2008 layout
                    //TODO: see if there is any way of allowing letters as chapter indicators and then map them to the CEI2008 layout
                    $fromChapter = intval( $fromChapter ); 
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
        if( $updatePreferOrigin ){
            $this->currentPreferOrigin = $preferorigin;
        }
        $toChapter = $chapter;
        $toVerse = $verse;
    }

    public function FormulateSQLQueries(){
        foreach ( $this->BBQUOTE->REQUESTED_VERSIONS as $version ) {
            $this->i = 0;
            $this->currentRequestedVariant = $version;
            foreach ( $this->queries as $query ) {
                $this->currentQuery = $query;
                $this->currentFullQuery = $query;
                $this->currentChapter = "";

                // Retrieve and store the book in the query string,if applicable
                $matchedBook = $this->captureBookIndicator();

                $this->setBookAndVariant( $matchedBook );

                $this->initSQLStatement();

                $this->validateVerseOriginPreference();

                //NOTE: considering a dash can have multiple meanings (range of verses in same chapter, range of verses over chapters, range of chapters),
                //      whereas a non-consecutive verse indicator has just one meaning;
                //      also considering that the non-consecutive verse indicator has to do with the smallest unit in a Bible reference = verse,
                //      whereas a dash could have to do either with verses or with chapters;
                //      we start our interpretation of the Bible reference from what is clear and certain
                //      and is the smallest possible unit that we have to work with.
                //      So if there is there are non-consecutive verses, we start splitting up the reference around the non-consecutive verses indicator ( or indicators )
                //NOTE: We have already capture the book, so we are not dealing with the book in the following calculations
                //      However for our own sanity, the book is included in the examples to help understand what is happening
                //      This symbol will be used to indicate splitting into left hand and right hand sections: <=|=>

                if ( self::queryContainsNonConsecutiveVerses( $this->currentQuery ) ) {                        // EXAMPLE: John 3,16.18
                    $nonConsecutiveChunks = self::getNonConsecutiveChunks( $this->currentQuery );              // John 3,16 <=|=> 18
                    foreach ( $nonConsecutiveChunks as $chunk ) {
                        $this->originalQueries[$this->nn] = $this->currentFullQuery;
                        //IF the chunk is not simply a single verse, but is a range of consecutive VERSES or CHAPTERS 
                        //    ( EXAMPLE: John 3,16-18.20       OR John 3,16.18-20        OR John 3,16-18.20-22 )
                        //    (         John 3,16-18 <=|=> 20 OR John 3,16 <=|=> 18-20  OR John 3,16-18 <=|=> 20-22 )
                        if ( self::chunkContainsRange( $chunk ) ) {
                            $range = self::getRange( $chunk );
                            //IF we have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  ( EXAMPLE: John 3,16-18.20 )
                            //  ( John 3,16-18 <=|=> 20 )
                            //  ( John 3 <=> 16-18 )
                            if ( self::chunkContainsChapterVerseConstruct( $range['from'] ) ) {
                                //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                $cvConstructLeft = self::getChapterVerseFromConstruct( $range['from'] );
                                $this->currentChapter = $cvConstructLeft['chapter'];

                                $this->mapReference( $cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true );

                                //IF we have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                //  ( EXAMPLE: John 3,16-4,5.8 )
                                //  ( John 3,16 <=|=> 4,5 )
                                if ( self::chunkContainsChapterVerseConstruct( $range['to'] ) ) {
                                    //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                    $cvConstructRight = self::getChapterVerseFromConstruct( $range['to'] );
                                    $this->currentChapter = $cvConstructRight['chapter'];

                                    $this->mapReference( $cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true );

                                    $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( ( chapter = " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'] . " )";
                                    $this->accountForMultipleChapterDifference( $cvConstructLeft, $cvConstructRight );
                                    $this->sqlQueries[$this->nn] .= " OR ( chapter = " . $cvConstructRight['chapter'] . " AND verse <= " . $cvConstructRight['verse'] . " ) )";
                                }
                                //ELSEIF we do NOT have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                // ( EXAMPLE: John 3,16-18.20 )
                                else {
                                    $this->mapReference( $this->currentChapter, $range['to'], $this->currentChapter, $range['to'], true );

                                    $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter >= " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'] . " )";
                                    $this->sqlQueries[$this->nn] .= " AND ( chapter <= " . $this->currentChapter . " AND verse <= " . $range['to'] . " )";
                                }
                            }
                            //ELSEIF we DO NOT have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  ( EXAMPLE: John 3,16.18-20 )
                            //  (  John 3,16 <=|=> 18-20 )
                            //  (  18 <=> 20 )
                            else {
                                $this->mapReference( $this->currentChapter, $range['from'], $this->currentChapter, $range['from'], true );
                                $this->mapReference( $this->currentChapter, $range['to'], $nullChapter, $range['to'], false );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter = " . $this->currentChapter . " AND verse >= " . $range['from'] . " AND verse <= " . $range['to'] . " )";
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
                            if ( self::chunkContainsChapterVerseConstruct( $chunk ) ) {
                                $cvConstruct = self::getChapterVerseFromConstruct( $chunk );
                                $this->currentChapter = $cvConstruct['chapter'];

                                $this->mapReference( $cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter = " . $cvConstruct['chapter'] . " AND verse = " . $cvConstruct['verse'] . " )";
                            } 
                            //ELSEIF the non consecutive chunk DOES NOT contain a chapter reference
                            //  ( EXAMPLE: John 3,16.18 )
                            //  (    John 3,16 <=|=> 18 )
                            //  ( 18 )
                            else {

                                $this->mapReference( $this->currentChapter, $chunk, $this->currentChapter, $chunk, true );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter = " . $this->currentChapter . " AND verse = " . $chunk . " )";
                            }
                        }

                        $this->finalizeQuery();
                        
                        $this->nn++;
                    }
                }
                else { //ELSEIF the request DOES NOT contain non-consecutive verses ( EXAMPLE: John 3,16 )
                    if ( self::chunkContainsRange( $this->currentQuery ) ) {    // EXAMPLE: John 3,16-18
                        $this->originalQueries[$this->nn] = $this->currentFullQuery;
                        $range = self::getRange( $chunk );                                        // John 3,16 <=|=> 18
                        //IF there is a chapter indicator on the left hand side of the range of consecutive verses
                        //    ( EXAMPLE: John 3,16-18 )
                        //    (    John 3,16 <=|=> 18 )
                        //    (    John 3,16 )
                        if ( self::chunkContainsChapterVerseConstruct( $range['from'] ) ) {
                            $cvConstructLeft = self::getChapterVerseFromConstruct( $range['from'] );
                            $this->currentChapter = $cvConstructLeft['chapter'];

                            $this->mapReference( $cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true );

                            //IF there is also a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-4,5 )
                            //    (    John 3,16 <=|=> 4,5 )
                            //    (    4,5 )
                            if ( self::chunkContainsChapterVerseConstruct( $range['to'] ) ) {
                                $cvConstructRight = self::getChapterVerseFromConstruct( $range['to'] );

                                $this->mapReference( $cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( ( chapter = " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'] . " )";
                                //what if the difference between chapters is greater than 1? Say: John 3,16-6,2 ?
                                $this->accountForMultipleChapterDifference( $cvConstructLeft, $cvConstructRight );
                                $this->sqlQueries[$this->nn] .= " OR ( chapter = " . $cvConstructRight['chapter'] . " AND verse <= " . $cvConstructRight['verse'] . " ) )";
                            }
                            //ELSEIF there is NOT a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-18 )
                            //    (    John 3,16 <=|=> 18 )
                            //    (    18 )
                            else {
                              $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter >= " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'];

                              $this->mapReference( $cvConstructLeft['chapter'], $range['to'], $mappedChapter, $range['to'], true );

                              $this->sqlQueries[$this->nn] .= " AND chapter <= " . $mappedChapter . " AND verse <= " . $range['to'];
                            }
                        }
                        //ELSEIF there is NOT a chapter/verse indicator on the left hand side of the range of consecutive verses OR chapters
                        //    this means that we are dealing with consecutive CHAPTERS and not VERSES
                        //     ( EXAMPLE: John 3-4 )
                        //     ( EXAMPLE: 3 <=|=> 4 )
                        else {
                            $this->mapReference( $range['from'], null, $range['from'], $nullVerse, true );
                            $this->mapReference( $range['to'], null, $range['to'], $nullVerse, false );

                            $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter >= " . $range['from'] . " AND chapter <= " . $range['to'];
                        }
                    }
                    //ELSEIF the request DOES NOT contain a range of consecutive verses OR chapters
                    //    ( EXAMPLE: John 3,16 )
                    else {
                        //IF we DO have a chapter/verse indicator
                        if ( self::chunkContainsChapterVerseConstruct( $this->currentQuery ) ) {
                            $this->originalQueries[$this->nn] = $this->currentFullQuery;
                            $cvConstruct = self::getChapterVerseFromConstruct( $this->currentQuery );
                            $this->currentChapter = $cvConstruct['chapter'];

                            $this->mapReference( $cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true );

                            $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter = " . $cvConstruct['chapter'] . " AND verse = " . $cvConstruct['verse'];
                        } 
                        //ELSEIF we are dealing with just a single chapter
                        //    ( EXAMPLE: John 3 )
                        else {
                            $this->originalQueries[$this->nn] = $this->currentFullQuery;
                            $this->currentChapter = $this->currentQuery;

                            $this->mapReference( $this->currentChapter, null, $mappedChapter, $nullVerse, true );

                            $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter = " . $mappedChapter;
                        }
                    }

                    $this->finalizeQuery();

                    $this->nn++;
                }

                $this->i++;
            }

        }

        $this->BBQUOTE->formulatedQueries = $this->sqlQueries;
        $this->BBQUOTE->originalQueries = $this->originalQueries;
        $this->BBQUOTE->formulatedVariants = $this->queriesVersions;
    }

}
