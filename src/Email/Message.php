<?php

/*******************************************************************************
*                                                                              *
*   Asinius\Email\Message                                                      *
*                                                                              *
*   Parse, modify, and print a raw email message.                              *
*                                                                              *
*   LICENSE                                                                    *
*                                                                              *
*   Copyright (c) 2020 Rob Sheldon <rob@rescue.dev>                            *
*                                                                              *
*   Permission is hereby granted, free of charge, to any person obtaining a    *
*   copy of this software and associated documentation files (the "Software"), *
*   to deal in the Software without restriction, including without limitation  *
*   the rights to use, copy, modify, merge, publish, distribute, sublicense,   *
*   and/or sell copies of the Software, and to permit persons to whom the      *
*   Software is furnished to do so, subject to the following conditions:       *
*                                                                              *
*   The above copyright notice and this permission notice shall be included    *
*   in all copies or substantial portions of the Software.                     *
*                                                                              *
*   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS    *
*   OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF                 *
*   MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.     *
*   IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY       *
*   CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,       *
*   TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE          *
*   SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.                     *
*                                                                              *
*   https://opensource.org/licenses/MIT                                        *
*                                                                              *
*******************************************************************************/

namespace Asinius\Email;


/*******************************************************************************
*                                                                              *
*   Constants                                                                  *
*                                                                              *
*******************************************************************************/

//  Assorted warnings for malformed messages.
defined('NO_MESSAGE_ID')            or define('NO_MESSAGE_ID', 1);
defined('MULTIPLE_MESSAGE_IDS')     or define('MULTIPLE_MESSAGE_IDS', 2);
defined('NO_SUBJECT')               or define('NO_SUBJECT', 4);
defined('MULTIPLE_SUBJECTS')        or define('MULTIPLE_SUBJECTS', 8);
defined('NO_FROM')                  or define('NO_FROM', 16);
defined('MULTIPLE_FROM')            or define('MULTIPLE_FROM', 32);
defined('NO_RECEIVED')              or define('NO_RECEIVED', 64);
defined('NO_DATE')                  or define('NO_DATE', 128);
defined('MULTIPLE_DATES')           or define('MULTIPLE_DATES', 256);
defined('NO_VALID_DATE')            or define('NO_VALID_DATE', 512);
defined('NO_TO_ADDRESS')            or define('NO_TO_ADDRESS', 1024);



/*******************************************************************************
*                                                                              *
*   \Asinius\Email\Message                                                     *
*                                                                              *
*******************************************************************************/

class Message
{

    protected $_imap_connection = null;
    protected $_message_id      = '';
    protected $_warnings        = 0;


    use \Asinius\DatastreamProperties;
    use \Asinius\CallerInfo;


    /**
     * Set one or more "warning flags" for mangled or weird messages.
     *
     * @internal
     *
     * @param   array       $headers
     *
     * @return  void
     */
    protected function _set_warnings ($headers)
    {
        if ( ! isset($headers['message_id']) ) {
            $this->_warnings |= NO_MESSAGE_ID;
        }
        else if ( is_array($headers['message_id']) ) {
            $this->_warnings |= MULTIPLE_MESSAGE_IDS;
        }
        if ( ! isset($headers['subject']) ) {
            $this->_warnings |= NO_SUBJECT;
        }
        else if ( is_array($headers['subject']) ) {
            $this->_warnings |= MULTIPLE_SUBJECTS;
        }
        if ( ! isset($headers['from']) ) {
            $this->_warnings |= NO_FROM;
        }
        else if ( is_array($headers['from']) ) {
            $this->_warnings |= MULTIPLE_FROM;
        }
        if ( strlen(implode('', $headers['received'])) < 1 ) {
            $this->_warnings |= NO_RECEIVED;
        }
        if ( ! isset($headers['date']) ) {
            $this->_warnings |= NO_DATE;
        }
        else if ( is_string($headers['date']) ) {
            if ( date_create($headers['date']) === false ) {
                $this->_warnings |= NO_VALID_DATE;
            }
        }
        else if ( is_array($headers['date']) ) {
            $this->_warnings |= MULTIPLE_DATES;
            $this->_warnings |= NO_VALID_DATE;
            foreach ($headers['date'] as $possible_date) {
                if ( date_create($headers['date']) !== false ) {
                    $this->_warnings &= ~NO_VALID_DATE;
                    break;
                }
            }
        }
        if ( ! isset($headers['delivered_to']) && ! isset($headers['to']) ) {
            $this->_warnings |= NO_TO_ADDRESS;
        }
    }


    /**
     * "Unfold" one or more lines of headers and return them as an array of
     * lines in "label: value" format.
     *
     * @param   array       $lines
     *
     * @return  array
     */
    protected function _unfold_headers ($lines)
    {
        $headers = [];
        $n = count($lines);
        for ( $i = 0; $i < $n; $i++ ) {
            if ( empty(trim($lines[$i])) ) {
                continue;
            }
            $line = $lines[$i];
            //  Check the next header line and "unfold" it if it appears to belong
            //  to the current line.
            while ( ($i+1) < $n && strspn($lines[$i+1], " \t") > 0 ) {
                //  This gets a little tricky. RFC 5322
                //  (https://tools.ietf.org/html/rfc5322#section-2.2.3)
                //  does not provide guidance on how to handle long unbroken lines
                //  without obvious delimiters. Some guesswork is done here to
                //  choose whether to unfold the line with or without whitespace.
                if ( strlen($lines[$i]) >= 78 || substr($lines[$i], -1) == ' ' ) {
                    $line .= ltrim($lines[++$i]);
                }
                else {
                    $line .= ' ' . ltrim($lines[++$i]);
                }
            }
            $headers[] = $line;
        }
        return $headers;
    }


    /**
     * "Fold" one or more lines of headers and return them as an array of
     * lines wrapped to a 78-character limit.
     *
     * @param   array       $lines
     *
     * @return  array
     */
    protected function _fold_headers ($headers)
    {
        $break_lines_on = [' with ' => 'before', ' on ' => 'before', '; ' => 'after', '(' => 'before', ')' => 'after', ',' => 'after', ' ' => 'after', ';' => 'after', '>' => 'after'];
        $lines = [];
        foreach ($headers as $header) {
            while ( strlen($header) > 78 ) {
                $best_break = 4;
                $chunk = substr($header, 0, 78);
                foreach ($break_lines_on as $delimiter => $position) {
                    if ( ($i = strripos($chunk, $delimiter)) !== false ) {
                        if ( $position == 'after' ) {
                            $i += strlen($delimiter);
                        }
                        if ( $i > $best_break && $i < 78 ) {
                            $best_break = $i;
                        }
                    }
                }
                if ( $best_break > 4 ) {
                    //  If the line is being broken on whitespace, the whitespace
                    //  should be preserved at the end of the current line.
                    if ( $header[$best_break] == ' ' ) {
                        $best_break++;
                    }
                    $lines[] = substr($header, 0, $best_break);
                    $header = '    ' . ltrim(substr($header, $best_break));
                }
                else {
                    //  No break available. For now, let's just hard-break
                    //  the line and let the next MTA deal with it.
                    $lines[] = substr($header, 0, 78);
                    $header = '    ' . substr($header, 78);
                }
            }
            $lines[] = $header;
        }
        return $lines;
    }


    /**
     * Create a new email message.
     * 
     * @param   string      $content
     *
     * @throws  \RuntimeException
     */
    public function __construct ($content = null, $message_id = '')
    {
        if ( is_null($content) ) {
            return;
        }
        if ( is_resource($content) ) {
            //  Assume that the message could be tens of MB.
            $headers = [];
            while ( ($line = fgets($content)) !== false ) {
                $line = trim($line, "\r\n");
                if ( strlen($line) == 0 ) {
                    break;
                }
                $headers[] = $line;
            }
            $this->raw_header = implode("\n", $headers);
            //  $raw_body is left pointing to the connected resource so that
            //  big messages don't have to be loaded into PHP's heap if the
            //  message content isn't going to be modified.
            $this->raw_body = $content;
        }
        else if ( is_object($content) && is_a($content, '\Asinius\Imap\Connection') && $this->_caller_is(['Asinius\Imap\Connection', 'Asinius\Imap\Folder']) ) {
            $this->_imap_connection = $content;
            $this->_message_id = $message_id;
            return;
        }
        else if ( is_string($content) ) {
            //  If it's being passed as a simple string, assume the message isn't
            //  absurdly large.
            $header_and_body = preg_split('/(\r\n)(\r\n)|\r\r|\n\n/', $content, 2);
            if ( count($header_and_body) != 2 ) {
                throw new \RuntimeException("Failed to parse message content; missing headers or body.");
            }
            $this->raw_header = $header_and_body[0];
            $this->raw_body   = $header_and_body[1];
        }
        else {
            throw new \RuntimeException("Can't create an \Asinius\Email\Message object from this value", \Asinius\EINVAL);
        }
        $this->_lock_property('raw_header');
        $this->_lock_property('raw_body');
    }


    /**
     * Return the headers for this email, in a key => value StrictArray.
     *
     * @return  \Asinius\StrictArray
     */
    public function get_headers ()
    {
        if ( ! isset($this->raw_header) ) {
            $this->raw_header = '';
            //  Attempt to retrieve message header from imap connection if available.
            if ( ! is_null($this->_imap_connection) && is_array($this->_message_id) && isset($this->_message_id['uid']) ) {
                $this->raw_header = $this->_imap_connection->get_message_headers($this->_message_id['path'], $this->_message_id['uid']);
            }
            $this->_lock_property('raw_header');
        }
        $headers = new \Asinius\StrictArray();
        $headers->set_case_insensitive();
        $lines = $this->_unfold_headers(preg_split('/(\r\n)|\r|\n/', $this->raw_header));
        foreach ($lines as $line) {
            if ( strpos($line, ':') === false ) {
                //  Invalid header; ignore it.
                continue;
            }
            list($fieldname, $fieldbody) = explode(':', $line, 2);
            $fieldbody = ltrim($fieldbody);
            //  https://tools.ietf.org/html/rfc2822#section-2.2
            //  A -ton- of applications use dashes in fieldnames, so no special
            //  translation is done to fieldnames.
            if ( isset($headers[$fieldname]) ) {
                if ( is_array($headers[$fieldname]) ) {
                    $headers[$fieldname][] = $fieldbody;
                }
                else {
                    $headers[$fieldname] = [$headers[$fieldname], $fieldbody];
                }
            }
            else {
                $headers[$fieldname] = $fieldbody;
            }            
        }
        //  Ensure that "received:" headers always exist as an array.
        if ( ! isset($headers['received']) ) {
            $headers['received'] = [];
        }
        else if ( ! is_array($headers['received']) ) {
            $headers['received'] = [$headers['received']];
        }
        //  Set some flags for strange behavior.
        $this->_set_warnings($headers);
        $this->headers = $headers;
        $this->_lock_property('headers');
        return $headers;
    }


    /**
     * Return this message's uid from the imap folder it's stored in, if applicable.
     * Returns an empty string if this message doesn't belong to an imap mailbox.
     * 
     * @return  mixed
     */
    public function get_id ()
    {
        return $this->_message_id;
    }


    /**
     * Return the message id from the email's headers.
     *
     * @return  string
     */
    public function get_message_id ()
    {
        $message_id = '';
        if ( isset($this->headers['message_id']) ) {
            if ( is_array($this->headers['message_id']) ) {
                //  Return the longest message id.
                foreach ($this->headers['message_id'] as $candidate) {
                    if ( strlen($message_id) < strlen($candidate) ) {
                        $message_id = $candidate;
                    }
                }
            }
            else {
                $message_id = $this->headers['message_id'];
            }
        }
        $this->message_id = $message_id;
        $this->_lock_property('message_id');
        return $message_id;
    }


    /**
     * Return and parse the date: header in the email.
     *
     * @return \DateTime
     */
    public function get_date ()
    {
        if ( ! isset($this->headers['date']) ) {
            return null;
        }
        //  Don't cache this property because it's returned by reference by
        //  default and other code may accidentally change the message date.
        $date = $this->headers['date'];
        if ( is_string($date) ) {
            try {
                $possible_date = date_create($date);
            } catch (Exception $e) {
                ;
            }
            if ( $possible_date !== false ) {
                return new \DateTime($date);
            }
        }
        if ( is_array($date) ) {
            foreach ($date as $date_line) {
                try {
                    $possible_date = date_create($date_line);
                } catch (Exception $e) {
                    ;
                }
                if ( $possible_date !== false ) {
                    return $possible_date;
                }
            }
        }
        //  TODO: in the future, a valid date can probably be extracted from
        //  the received: headers.
        return null;
    }


    /**
     * Generate a key for this email. This is intended to be unique for every
     * individual email message. The message id header is used if available,
     * otherwise other headers are checked. The string returned is a sha256 hash.
     *
     * @return  string
     */
    public function get_key ()
    {
        $headers = $this->headers;
        $hash_source = '';
        if ( ! empty($this->message_id) ) {
            $hash_source = $this->message_id;
        }
        else if ( ! empty($headers['received']) && isset($headers['received'][0]) && strlen($headers['received'][0]) > 128 ) {
            $hash_source = $headers['received'][0];
        }
        else if ( ! empty($headers['date']) ) {
            $date = new \DateTime($this->headers['date']);
            //  Need date + one other identifiable source.
            if ( ! empty($headers['subject']) ) {
                $hash_source = $date->format('c') . ' ' . $headers['subject'];
            }
            else if ( ! empty($headers['return_path']) ) {
                $hash_source = $date->format('c') . ' ' . $headers['return_path'];
            }
        }
        if ( strlen($hash_source) > 0 ) {
            return hash('sha256', $hash_source);
        }
        return '';
    }


    /**
     * Return the subject line from the email's headers.
     *
     * @return  string
     */
    public function get_subject ()
    {
        $subject = '';
        if ( isset($this->headers['subject']) ) {
            $subject = $this->headers['subject'];
        }
        $this->subject = $subject;
        $this->_lock_property('subject');
        return $subject;
    }


    /**
     * Add a new header to this email. If a header already exists with the same
     * field label, this header will not replace the first one.
     *
     * @param  string       $name
     * @param  string       $value
     *
     * @return  void
     */
    public function add_header ($name, $value)
    {
        if ( ! isset($this->headers[$name]) ) {
            $this->headers[$name] = $value;
        }
        else if ( is_array($this->headers[$name]) ) {
            $this->headers[$name][] = $value;
        }
        else {
            $this->headers[$name] = [$this->headers[$name], $value];
        }
    }


    /**
     * Return the size, in bytes, of this message. If the message is currently in
     * STDIN or some other resource, this function will cause it to be read in its
     * entirety (since there's no other way to count the number of bytes in an
     * email).
     *
     * The value returned by this function isn't perfectly accurate! Email messages
     * get transformed while en route, with line breaks added or removed and some
     * content encoded in one way or another. This function only counts the number
     * of bytes in the current headers + body, with no added line breaks. It will
     * give you an approximation of the final message size.
     *
     * @return  int
     */
    public function get_size ()
    {
        $bytes = 0;
        if ( ! is_null($this->_imap_connection) && is_array($this->_message_id) && isset($this->_message_id['uid']) ) {
            //  Retrieve the message structure info from the imap connection.
            $message_info = $this->_imap_connection->get_message_structure($this->_message_id['path'], $this->_message_id['uid']);
            if ( isset($message_info['bytes']) ) {
                $bytes += $message_info['bytes'];
            }
            if ( isset($message_info['parts']) && is_array($message_info['parts']) ) {
                foreach ($message_info['parts'] as $part) {
                    if ( isset($part['bytes']) ) {
                        $bytes += $part['bytes'];
                    }
                }
            }
        }
        if ( $bytes == 0 && is_resource($this->raw_body) ) {
            $body = '';
            while ( ($chunk = fread($this->raw_body)) !== false ) {
                $body .= $chunk;
            }
            fclose($this->raw_body);
            $this->raw_body = $body;
        }
        if ( $bytes == 0 && is_string($this->raw_body) ) {
            $bytes = strlen($this->raw_body);
        }
        //  Add the size of the headers.
        $bytes += array_sum(array_map(function($header){
            if ( is_array($header) ) {
                return strlen(implode("\n", $header));
            }
            return strlen($header);
        }, $this->headers));
        $this->size = $bytes;
        $this->_lock_property('size');
        return $bytes;
    }


    /**
     * Delete this message. This is only applicable to messages associated with
     * an imap mailbox. This function deletes the message from that imap mailbox.
     *
     * @return  boolean
     */
    public function delete ()
    {
        if ( ! is_null($this->_imap_connection) && is_array($this->_message_id) && isset($this->_message_id['uid']) ) {
            return $this->_imap_connection->delete_message($this->_message_id['path'], $this->_message_id['uid']);
        }
        return false;
    }


    /**
     * Write the current message to some destination. The message will be echoed
     * as plain text if $destination is null. A resource can also be supplied
     * (file, pipe, socket), or a callback function.
     *
     * @param   mixed       $destination
     *
     * @return  void
     */
    public function print ($destination = null)
    {
        //  Prepare headers for output.
        $header_lines = [];
        foreach ($this->headers as $key => $values) {
            if ( ! is_array($values) ) {
                $values = [$values];
            }
            foreach ($values as $value) {
                $header_lines[] = "$key: $value";
            }
        }
        $header_lines = $this->_fold_headers($header_lines);
        //  Create a $read closure based on the data type of $this->raw_body.
        //  This crude hack allows large message bodies to reside in STDIN or
        //  a file or somewhere else until print() is called, rather than being
        //  loaded in to memory unnecessarily.
        $content = $this->raw_body;
        if ( is_string($content) ) {
            $readf = function() use (&$content){
                $out = $content;
                $content = false;
                return $out;
            };
        }
        else if ( is_resource($content) ) {
            $readf = function() use ($content){
                return fgets($content);
            };
        }
        else {
            throw new \RuntimeException("Can't print() this content");
        }
        //  Write the entire email message to the destination in a
        //  type-appropriate way.
        if ( is_null($destination) ) {
            $writef = function($chunk){
                echo $chunk;
            };
        }
        else if ( is_callable($destination) ) {
            $writef = $destination;
        }
        else if ( is_resource($destination) ) {
            $writef = function($chunk) use ($destination){
                fwrite($destination, $chunk);
            };
        }
        foreach ($header_lines as $line) {
            $writef("$line\n");
        }
        $writef("\n");
        while ( ($chunk = $readf()) !== false ) {
            $writef($chunk);
            if ( substr($chunk, -1) != "\n" ) {
                $writef("\n");
            }
        }
    }


}
