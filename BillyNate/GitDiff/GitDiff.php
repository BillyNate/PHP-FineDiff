<?php
namespace BillyNate\GitDiff;

use GorHill\FineDiff\FineDiff;
use BillyNate\GitLib\GitInt;

class GitDiff extends FineDiff {

    private $offset;

    public function getOpcodes() {
        $opcodes = array();
        $to_len = 0;
        $from_len = 0;
        $this->offset = 0;
        foreach ( $this->edits as $edit ) {
            $to_len += $edit->getToLen();
            $from_len += $edit->getFromLen();
            $opcodes[] = $this->getOpcode($edit);
        }

        return GitInt::decToVarint($from_len).GitInt::decToVarint($to_len).implode('', $opcodes);
    }

    public function getOpcode($edit) {
        $to_len = $edit->getToLen();
        $from_len = $edit->getFromLen();

        if ( $edit instanceof \GorHill\FineDiff\FineDiffCopyOp ) {
            
            $copyOpcode = self::createCopyOpcode($to_len, $this->offset);
            $this->offset += $from_len;
            return $copyOpcode;
        }
        else if ( $edit instanceof \GorHill\FineDiff\FineDiffDeleteOp ) {
            
            $this->offset += $from_len;
            return '';
        }
        else if ( $edit instanceof \GorHill\FineDiff\FineDiffInsertOp ) {
            
            return self::createInsertOpcode($edit->getText(), $to_len);
        }
        else /* if ( $edit instanceof FineDiffReplaceOp ) */ {
            
            $this->offset += $from_len;
            return self::createInsertOpcode($edit->getText(), $to_len);
        }
    }

    /**------------------------------------------------------------------------
     * Return an opcodes string describing the diff between a "From" and a
     * "To" string
     */
    public static function getDiffOpcodes($from, $to, $granularities = null, $greed=4, $encoding = null) {
        if ($encoding === null) {
            $encoding = mb_internal_encoding();
        }
        $diff = new GitDiff($from, $to, $granularities, $greed, $encoding);
        return $diff->getOpcodes();
    }

    /**------------------------------------------------------------------------
     * Generic opcodes parser, user must supply callback for handling
     * single opcode
     */
    public static function renderFromOpcodes($from, $opcodes, $callback, $encoding = null, $textToEntities=true) {
        if ( !is_callable($callback) ) {
            return;
        }
        if ( $encoding === null ) {
            $encoding = mb_internal_encoding();
        }

        $opcodes_len = mb_strlen($opcodes, $encoding);
        $from_offset = 0;

        $from_total_length = GitInt::varintToDec($opcodes, $str_len);
        $opcodes_offset = $str_len;
        $to_total_length = GitInt::varintToDec($opcodes, $str_len);
        $opcodes_offset += $str_len;

        while ( $opcodes_offset <  $opcodes_len ) {
            $opcode = ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding));
            $opcodes_offset ++;
            
            if ( $opcode & 0b10000000 ) { // copy characters from source
                $from_offset = 0;
                if ( $opcode & 0b00000001 ) {
                    $from_offset  = ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding));
                    $opcodes_offset ++;
                }
                //if $opcode has a bit at 00000010, add the ascii value of one bit (or byte?) (from packline) to $from_offset, but 8 positions to the left:
                if ( $opcode & 0b00000010 ) {
                    $from_offset |= ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding)) <<  8;
                    $opcodes_offset ++;
                }
                if ( $opcode & 0b00000100 ) {
                    $from_offset |= ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding)) << 16;
                    $opcodes_offset ++;
                }
                if ( $opcode & 0b00001000 ) {
                    $from_offset |= ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding)) << 24;
                    $opcodes_offset ++;
                }

                $from_length = 0;
                if ( $opcode & 0b00010000 ) {
                    $from_length  = ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding));
                    $opcodes_offset ++;
                }
                if ( $opcode & 0b00100000 ) {
                    $from_length |= ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding)) <<  8;
                    $opcodes_offset ++;
                }
                if ( $opcode & 0b01000000 ) {
                    $from_length |= ord(mb_substr($opcodes, $opcodes_offset, 1, $encoding)) << 16;
                    $opcodes_offset ++;
                }
                if ( $from_length == 0 ) {
                    $from_length = 0x10000;
                }

                call_user_func($callback, 'c', $from, $from_offset, $from_length, $encoding, false);
            }
            else { // insert characters from opcodes
                call_user_func($callback, 'i', $opcodes, $opcodes_offset, $opcode, $encoding, false);
                $opcodes_offset += $opcode;
            }
        }
    }

    /**------------------------------------------------------------------------
     *
     * Private section
     *
     */

    protected static function createCopyOpcode($to_len, $offset) {
        $opcode = 0b10000000;
        $offsetAndLength = '';

        if ( $offset & 0b11111111 )
        {
            $offsetAndLength .= chr($offset);
            $opcode |= 0b00000001;
        }
        if ( $offset >> 8 & 0b11111111 )
        {
            $offsetAndLength .= chr($offset >> 8 & 0b11111111);
            $opcode |= 0b00000010;
        }
        if ( $offset >> 16 & 0b11111111 )
        {
            $offsetAndLength .= chr($offset >> 16 & 0b11111111);
            $opcode |= 0b00000100;
        }
        if ( $offset >> 24 & 0b11111111 )
        {
            $offsetAndLength .= chr($offset >> 24 & 0b11111111);
            $opcode |= 0b00001000;
        }

        if ( $to_len != 0x10000 ) //else write out zero length, git knows this means 0x10000...
        {
            if ( $to_len & 0b11111111 )
            {
                $offsetAndLength .= chr($to_len);
                $opcode |= 0b00010000;
            }
            if ( $to_len >> 8 & 0b11111111 )
            {
                $offsetAndLength .= chr($to_len >> 8 & 0b11111111);
                $opcode |= 0b00100000;
            }
            if ( $to_len >> 16 & 0b11111111 )
            {
                $offsetAndLength .= chr($to_len >> 16 & 0b11111111);
                $opcode |= 0b01000000;
            }
        }

        return chr($opcode).$offsetAndLength;
    }

    protected static function createInsertOpcode($text, $to_len) {
        $string = '';
        $texts = self::mb_str_split($text, 127);
        $length = $to_len;
        for ( $i = 0; $i < count($texts); $i ++ ) {
            $string .= chr(min($length, 127)).$texts[$i];
            $length -= 127;
        }
        return $string;
    }
}