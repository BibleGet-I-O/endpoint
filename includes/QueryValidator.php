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

class QUERY_VALIDATOR {

    private BIBLEGET_QUOTE $BBQUOTE;
    private string $currentVariant      = "";
    private string $currentBook         = "";
    private int $bookIdxBase            = -1;
    private int $nonZeroBookIdx         = -1;
    private string $currentQuery        = "";
    private string $currentFullQuery    = "";

    const QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR                            = 0;
    const VALID_CHAPTER_MUST_FOLLOW_BOOK                                        = 1;
    const IS_VALID_BOOK_INDICATOR                                               = 2;
    const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR           = 3;
    const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS                     = 4;
    const CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS             = 5;
    const VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS                          = 6;
    const CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS   = 7;
    const CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES              = 8;

    function __construct( BIBLEGET_QUOTE $BBQUOTE ) {
        $this->BBQUOTE = $BBQUOTE;
    }

    static private function matchBookInQuery( string $query ) : array|bool {
        if( BIBLEGET_QUOTE::stringWithUpperAndLowerCaseVariants( $query ) ){
            if( preg_match( "/^([1-4]{0,1}((\p{Lu}\p{Ll}*)+))/u", $query, $res ) ){
                return $res;
            } else {
                return false;
            }
        } else {
            if( preg_match( "/^([1-4]{0,1}((\p{L}\p{M}*)+))/u", $query, $res ) ){
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
                $validation = (preg_match( "/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", $query ) || preg_match( "/^[1-4]{0,1}(\p{L}\p{M}*)+/u", $query ));
                break;
            case self::VALID_CHAPTER_MUST_FOLLOW_BOOK :
                if(BIBLEGET_QUOTE::stringWithUpperAndLowerCaseVariants( $query ) ){
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

    static private function queryContainsNonConsecutiveVerses( string $query ) : bool {
        return strpos( $query, "." ) !== false;
    }

    static private function forceArray( $element ) : array {
        if ( !is_array( $element ) ) {
            $element = [ $element ];
        }
        return $element;
    }

    static private function getAllVersesAfterDiscontinuousVerseIndicator( string $query ) : array {
        if( preg_match_all( "/\.([1-9][0-9]{0,2})$/", $query, $discontinuousVerses ) ){
            $discontinuousVerses[1] = self::forceArray( $discontinuousVerses[1] );
            return $discontinuousVerses;
        } else {
            return [[],[]];
        }
    }

    static private function getVerseAfterChapterVerseSeparator( string $query ) : array {
        if( preg_match( "/,([1-9][0-9]{0,2})/", $query, $verse ) ){
            return $verse;
        } else {
            return [[],[]];
        }
    }

    static private function chunkContainsChapterVerseConstruct( string $chunk ) : bool {
        return strpos( $chunk, "," ) !== false;
    }

    static private function getAllChapterIndicators( string $query ) : array {
        if( preg_match_all( "/([1-9][0-9]{0,2})\,/", $query, $chapterIndicators ) ){
            $chapterIndicators[1] = self::forceArray( $chapterIndicators[1] );
            return $chapterIndicators;
        } else {
            return ["",[]];
        }
    }

    private function validateAndSetBook( array|bool $matchedBook ) : bool {
        if ( $matchedBook !== false ) {
            $this->currentBook = $matchedBook[0];
            if ( $this->validateBibleBook() === false ) {
                return false;
            } else {
                $this->currentQuery = str_replace( $this->currentBook, "", $this->currentQuery );
                return true;
            }
        } else {
            return true;
        }
    }

    private function validateChapterVerseConstructs() : bool {
        $chapterVerseConstructCount = substr_count( $this->currentQuery, "," );
        if ( $chapterVerseConstructCount > 1 ) {
            return $this->validateMultipleVerseSeparators();
        } elseif ( $chapterVerseConstructCount == 1 ) {
            $parts = explode( ",", $this->currentQuery );
            if ( strpos( $parts[1], '-' ) ) {
                if( $this->validateRightHandSideOfVerseSeparator( $parts ) === false ) {
                    return false;
                }
            } else {
                if( $this->validateVersesAfterChapterVerseSeparators( $parts ) === false ){
                    return false;
                }
            }

            $discontinuousVerses = self::getAllVersesAfterDiscontinuousVerseIndicator( $this->currentQuery );
            $highverse = array_pop( $discontinuousVerses[1] );
            if( $this->highVerseOutOfBounds( $highverse, $parts ) ) {
                return false;
            }
        }
        return true;
    }

    private function queryViolatesAnyRuleOf( string $query, array $rules ) : bool {
        foreach( $rules as $rule ) {
            if( self::validateRuleAgainstQuery( $rule, $query ) === false ){
                $this->BBQUOTE->addErrorMessage( $rule );
                $this->BBQUOTE->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    private function isValidBookForVariant( string $variant ) : bool {
        return ( in_array( $this->currentBook, $this->BBQUOTE->INDEXES[$variant]["biblebooks"] ) || in_array( $this->currentBook, $this->BBQUOTE->INDEXES[$variant]["abbreviations"] ) );
    }

    private function validateBibleBook() : bool {
        $bookIsValid = false;
        foreach ( $this->BBQUOTE->REQUESTED_VERSIONS as $variant ) {
            if ( $this->isValidBookForVariant( $variant ) ) {
                $bookIsValid = true;
                $this->currentVariant = $variant;
                $this->bookIdxBase = $this->BBQUOTE::idxOf( $this->currentBook, $this->BBQUOTE->BIBLEBOOKS );
                break;
            }
        }
        if( !$bookIsValid ) {
            $this->bookIdxBase = $this->BBQUOTE::idxOf( $this->currentBook, $this->BBQUOTE->BIBLEBOOKS );
            if( $this->bookIdxBase !== false){
                $bookIsValid = true;
            } else {
                $this->BBQUOTE->addErrorMessage( sprintf( 'The book %s is not a valid Bible book. Please check the documentation for a list of correct Bible book names, whether full or abbreviated.', $this->currentBook ) );
                $this->BBQUOTE->incrementBadQueryCount();
            }
        }
        $this->nonZeroBookIdx = $this->bookIdxBase + 1;
        return $bookIsValid;
    }

    private function validateChapterIndicators( array $chapterIndicators ) : bool {

        foreach ( $chapterIndicators[1] as $chapterIndicator ) {
            foreach ( $this->BBQUOTE->INDEXES as $jkey => $jindex ) {
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapter_limit = $jindex["chapter_limit"][$bookidx];
                if ( $chapterIndicator > $chapter_limit ) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$s>, and <%4$d> must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $chapterIndicator, $this->currentBook, $jkey, $chapter_limit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return false;
                }
            }
        }

        return true;

    }

    private function validateMultipleVerseSeparators() : bool {
        if ( !strpos( $this->currentQuery, '-' ) ) {
            $this->BBQUOTE->addErrorMessage( "You cannot have more than one comma and not have a dash!" );
            $this->BBQUOTE->incrementBadQueryCount();
            return false;
        }
        $parts = explode( "-", $this->currentQuery );
        if ( count( $parts ) != 2 ) {
            $this->BBQUOTE->addErrorMessage( "You seem to have a malformed querystring, there should be only one dash." );
            $this->BBQUOTE->incrementBadQueryCount();
            return false;
        }
        foreach ( $parts as $part ) {
            $pp = array_map( "intval", explode( ",", $part ) );
            foreach ( $this->INDEXES as $jkey => $jindex ) {
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval( $chapters_verselimit[$pp[0] - 1] );
                if ( $pp[1] > $verselimit ) {
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $pp[1], $this->currentBook, $pp[0], $jkey, $verselimit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    private function validateRightHandSideOfVerseSeparator( array $parts ) : bool {
        if ( preg_match_all( "/[,\.][1-9][0-9]{0,2}\-([1-9][0-9]{0,2})/", $this->currentQuery, $matches ) ) {
            $matches[1] = self::forceArray( $matches[1] );
            $highverse = intval( array_pop( $matches[1] ) );

            foreach ( $this->BBQUOTE->INDEXES as $jkey => $jindex ) {
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );

                if ( $highverse > $verselimit ) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    private function validateVersesAfterChapterVerseSeparators( array $parts ) : bool {
        $versesAfterChapterVerseSeparators = self::getVerseAfterChapterVerseSeparator( $this->currentQuery );

        $highverse = intval( $versesAfterChapterVerseSeparators[1] );
        foreach ( $this->INDEXES as $jkey => $jindex ) {
            $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
            $chapters_verselimit = $jindex["verse_limit"][$bookidx];
            $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );
            if ( $highverse > $verselimit ) {
                /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->BBQUOTE->addErrorMessage( sprintf( $msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit ) );
                $this->BBQUOTE->incrementBadQueryCount();
                return false;
            }
        }
        return true;
    }

    private function highVerseOutOfBounds( $highverse, array $parts ) : bool {
        foreach ( $this->BBQUOTE->INDEXES as $jkey => $jindex ) {
            $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
            $chapters_verselimit = $jindex["verse_limit"][$bookidx];
            $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );
            if ( $highverse > $verselimit ) {
                /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->BBQUOTE->addErrorMessage( sprintf( $msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit ) );
                $this->BBQUOTE->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    private function chapterOutOfBounds( array $chapters ) : bool {
        foreach ( $chapters as $zchapter ) {
            foreach ( $this->INDEXES as $jkey => $jindex ) {
                
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapter_limit = $jindex["chapter_limit"][$bookidx];
                if ( intval( $zchapter ) > $chapter_limit ) {
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $zchapter, $this->currentBook, $jkey, $chapter_limit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return true;
                }
            }
        }
        return false;
    }

    public function ValidateQueries() : bool {
        //at least the first query must start with a book reference, which may have a number from 1 to 3 at the beginning
        //echo "matching against: ".$queries[0]."<br />";
        if ( $this->queryViolatesAnyRuleOf( $this->BBQUOTE->queries[0], [ self::QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR ] ) ) {
            $this->BBQUOTE->outputResult();
        }


        foreach ( $this->BBQUOTE->queries as $query ) {
            $this->currentFullQuery = $query;
            $this->currentQuery = $query;

            if( $this->queryViolatesAnyRuleOf( $this->currentQuery, [ self::VALID_CHAPTER_MUST_FOLLOW_BOOK ] ) ){
                return false;
            }

            $matchedBook = self::matchBookInQuery( $this->currentQuery );
            if ( $this->validateAndSetBook( $matchedBook ) === false ) {
                continue;
            }

            if ( self::queryContainsNonConsecutiveVerses( $this->currentQuery ) ) {
                $rules = [ 
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR,
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS
                ];
                if ( $this->queryViolatesAnyRuleOf( $this->currentQuery, $rules ) ) {
                    continue;
                }
            }

            if ( self::chunkContainsChapterVerseConstruct( $this->currentQuery ) ) {
                if ( $this->queryViolatesAnyRuleOf( $this->currentQuery, [ self::CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS ] ) ) {
                    continue;
                } else {
                    $chapterIndicators = self::getAllChapterIndicators( $this->currentQuery );
                    if( $this->validateChapterIndicators( $chapterIndicators ) === false ){
                        continue;
                    }

                    if( $this->validateChapterVerseConstructs() === false ){
                        continue;
                    }

                }
            } else {
                $chapters = explode( "-", $this->currentQuery );
                if( $this->chapterOutOfBounds( $chapters ) ){
                    continue;
                }
            }

            if ( strpos( $this->currentQuery, "-" ) ) {
                $rules = [
                    self::VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS,
                    self::CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS,
                    self::CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES
                ];
                if ( $this->queryViolatesAnyRuleOf( $this->currentQuery, $rules ) ) {
                    continue;
                }
            }
            $this->BBQUOTE->validatedVariants[] = $this->currentVariant;
            $this->BBQUOTE->validatedQueries[]  = $this->currentFullQuery;

        } //END FOREACH
        return true;
    }
}
