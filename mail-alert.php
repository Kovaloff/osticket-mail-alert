<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once(INCLUDE_DIR . 'class.mailer.php');
require_once('config.php');

class MailAlertPlugin extends Plugin {

    var $config_class = "MailAlertPluginConfig";

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        // Listen for osTicket to tell us it's made a new ticket or updated
        // an existing ticket:
        error_log("Mail alert plugin called.");
        Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
        Signal::connect('ticket.overdue', array($this, 'onTicketOverdue'));
        // Tasks? Signal::connect('task.created',array($this,'onTaskCreated'));
    }

    /**
     * What to do with a new Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Mail alert plugin called too early.");
            return;
        }

        // Convert any HTML in the message into text
        $plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());

        // Format the messages we'll send.
        $heading = sprintf('%s %s %s %.40s (%.40s) %s "%.40s"'
                , __("New Ticket")
                , __("created")
                , __("from")
                , $ticket->getName()->getFull()
                , $ticket->getEmail()
                , __("with subject")
                , $ticket->getSubject());
        $this->sendMailAlert($ticket, $heading, $plaintext);
    }

    /**
     * What to do with an Updated Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param ThreadEntry $entry
     * @return type
     */
    function onTicketUpdated(ThreadEntry $entry) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Mail alert plugin called too early.");
            return;
        }
        if (!$entry instanceof MessageThreadEntry) {
            // this was a reply or a system entry.. not a message from a user
            //return;
        }

        // Need to fetch the ticket from the ThreadEntry
        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) {
            // Admin created ticket's won't work here.
            return;
        }

        // Check to make sure this entry isn't the first (ie: a New ticket)
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            return;
        }
        // Convert any HTML in the message into text
        $plaintext = Format::html2text($entry->getBody()->getClean());

        // Format the messages we'll send
        $heading = sprintf('%s %s %s %.40s (%.40s) %s "%.40s"'
                , __("Ticket")
                , __("updated")
                , __("from")
                , $ticket->getName()->getFull()
                , $ticket->getEmail()
                , __("with subject")
                , $ticket->getSubject());
        $this->sendMailAlert($ticket, $heading, $plaintext, 'warning');
    }

    /**
     * What to do with an overdue Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketOverdue(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Mail alert plugin called too early.");
            return;
        }

        // Format the messages we'll send.
        $heading = sprintf('%s %s %s %.40s (%.40s) %s "%.40s"'
                , __("Ticket")
                , __("flagged as overdue")
                , __("from")
                , $ticket->getName()->getFull()
                , $ticket->getEmail()
                , __("with subject")
                , $ticket->getSubject());
        $this->sendMailAlert($ticket, $heading, $plaintext);
    }


    /**
     * A helper function that sends messages by mail. 
     * 
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $heading
     * @param string $body
     * @param string $colour
     * @throws \Exception
     */
    function sendMailAlert(Ticket $ticket, $heading, $body, $colour = 'good') {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Mail alert plugin called too early.");
            return;
        }
        $from_address = $this->getConfig()->get('mail-alert-from-address');
        if (!$from_address) {
            $ost->logError('MailAlert Plugin not configured', 'You need to read the Readme and configure a source mail address before using this.');
        }
        $to_address = $this->getConfig()->get('mail-alert-to-address');
        if (!$to_address) {
            $ost->logError('MailAlert Plugin not configured', 'You need to read the Readme and configure a destination mail address before using this.');
        }

        // Check the subject, see if we want to filter it.
        $regex_subject_ignore = $this->getConfig()->get('mail-alert-regex-subject-ignore');
        // Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            $ost->logDebug('Ignored Message', 'Mail notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            return;
        } else {
            error_log("$ticket_subject didn't trigger $regex_subject_ignore");
        }

        $heading = $this->format_text($heading);

        // Pull template from config, and use that. 
        $template          = $this->getConfig()->get('message-template');
        // Add our custom var
        $custom_vars       = [
            'mail_safe_message' => $this->format_text($body),
        ];
        $formatted_message = $ticket->replaceVars($template, $custom_vars);

        $payload = $formatted_message . '<h5>Url:</h5>'.$cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId();

        // Format the payload:
        //$message = utf8_encode($payload);
        $message = $payload;

        try {
            $mailer = new Mailer();
            $mailer->setFromAddress($from_address);
            $mailer->send($to_address,$heading,$message);
        } catch (\Exception $e) {
            $ost->logError('Mail alert posting issue!', $e->getMessage(), true);
            error_log('Error posting mail alert. ' . $e->getMessage());
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry        	
     * @return Ticket
     */
    function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter([
                    'id' => $entry->getThreadId()
                ])->values_flat('object_id')->first() [0];

        // Force lookup rather than use cached data..
        // This ensures we get the full ticket, with all
        // thread entries etc.. 
        return Ticket::lookup(array(
                    'ticket_id' => $ticket_id
        ));
    }

    /**
     * Formats text according to the 
     * formatting rules:https://api.slack.com/docs/message-formatting
     * 
     * @param string $text
     * @return string
     */
    function format_text($text) {
        return $text;
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source https://gravatar.com/site/implement/images/php/
     */
    function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array()) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

}
