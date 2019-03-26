<?php
include "vendor/autoload.php";
# composer require rmccue/Requests
Requests::register_autoloader();

# Email Options
$hostname = '{[host:port without brackets]/imap/ssl}INBOX';
$username = '[email username]';
$password = '[email password]';
$inbox = imap_open($hostname,$username,$password) or die('Cannot connect to email: ' . imap_last_error());
$emails = imap_search($inbox,'ALL');

# Slack Options
$slack['channel'] = "icinga"; # Default Slack channel to post in
$slack['hook'] = "[set to your hook url]"; # Default Slack Channel's webhook
#       "title": "*** Alert from Icinga ***", 
#      "title_link": "http://[redacted]/icingaweb2/",
# Above taken from the template for condensing
$slackTemplate = '{
  "username": "Icinga",
  "channel": "%icingaChannel%",
  "attachments": [
    {
      "fallback": "%icingaText%",
      "color": "%icingaColor%",
      "pretext": "*%icingaText%*",
      "text": "```%icingaAlert%```",
      "fields": [
        {
          "title": "Host",
          "value": "%icingaHost%\n%icingaIP%",
          "short": true
        },
        {
          "title": "Notify Time",
          "value": "%icingaTime%\n%icingaDate%",
          "short": true
        }
      ],
      "footer": "MyNode Icinga API",
      "ts": "'.time().'"
    }
  ]
}';

if($emails) {
    rsort($emails);
	foreach($emails as $emailid) {
		$overview = imap_fetch_overview($inbox, $emailid, 0); # Collect email information
		$message = imap_body($inbox, $emailid, 0); # Collect the email message
		$alert['message'] = $overview[0]->subject;
		$alert['email-date'] = $overview[0]->date;
        $alert['color'] = sprintf('#%06X', mt_rand(0, 0xFFFFFF));


    # This is required to put all content in a single line for explosion
    $message = str_replace("=\r", "", $message);
    $explode = explode("\r", $message);
        foreach($explode as $val) {
            # Explode the lines, find the needfuls, explode to get the value (tab separate)
            if(strpos($val, 'Info:') !== false) {
                $alert['info'] = str_replace(array('"', "'", "=3D"), array('','', '='), explode('    ', $val)['1']);
            }
            if(strpos($val, 'When:') !== false) {
                $dateArray = explode(' ', explode('    ', $val)['1']);
                $alert['when'] = "{$dateArray['1']} ({$dateArray['2']})";

                # Patch this up right quick
                $alert['date'] = str_replace("{$dateArray['1']} {$dateArray['2']}", "", $alert['email-date']);
            }
            if(strpos($val, 'Host:') !== false) {
                $alert['host'] = explode('    ', $val)['1'];
                switch($alert['host']) {
                        case '[match a second host]':
                        $alert['channel'] = '[secondary channel]';
                        $alert['slackhook'] = "[hook url for said channel]]";
                        break;
                        default:
                        $alert['channel'] = $slack['channel']; # Set back to default
                        $alert['slackhook'] = $slack['hook']; # See above
                        break;
                }
            }
            if(strpos($val, 'IPv4:') !== false) {
                $alert['ip'] = explode('    ', $val)['1'];
            }
        }

        # Build the Slack Message
        $postTemplate = str_replace(
            array(
                '%icingaText%',
                '%icingaAlert%',
                '%icingaTime%',
                '%icingaIP%',
                '%icingaLastCheck%',
                '%icingaHost%',
                '%icingaDate%',
                '%icingaColor%',
                '%icingaChannel%'
            ), 
            array(
                $alert['message'], # %iciginaText%
                $alert['info'], # %iciginaAlert% ['info']
                $alert['when'], # %iciginaTime%
                $alert['ip'], # %iciginaIP%
                explode(' ', $alert['when'])['0'].' @ '.$alert['date'], # %iciginaLastCheck%
                $alert['host'], # %iciginaHost%
                $alert['date'], # %iciginaDate%
                $alert['color'], # %iciginaColor%
                $alert['channel'] # %icingaChannel%

            ), $slackTemplate);

        # Send the Slack Notification
        $response = Requests::post($alert['slackhook'], array('Content-type' => 'application/json'), $postTemplate);
        var_dump($response->body);
        # So long and thanks for all the mail
        imap_delete($inbox, $emailid);
	}
} 
imap_expunge($inbox);
imap_close($inbox);
