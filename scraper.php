<?php
# Hurstville City Council scraper
require 'scraperwiki.php'; 
require 'simple_html_dom.php';
date_default_timezone_set('Australia/Sydney');

$terms_url   = "http://daenquiry.hurstville.nsw.gov.au/masterviewui/Modules/Applicationmaster/Default.aspx";
$cookie_file = "cookies.txt";

## Accept Terms and return Cookies
function accept_terms_get_cookies($terms_url, $cookie_file) {
    $curl = curl_init($terms_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($curl, CURLOPT_COOKIEFILE, $cookie_file);
    $terms_response = curl_exec($curl);
    curl_close($curl);

    preg_match('/<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="(.*)" \/>/', $terms_response, $viewstate_matches);
    $viewstate = $viewstate_matches[1];

    preg_match('/<input type="hidden" name="__EVENTVALIDATION" id="__EVENTVALIDATION" value="(.*)" \/>/', $terms_response, $eventvalidation_matches);
    $eventvalidation = $eventvalidation_matches[1];

    $postfields = array();
    $postfields['__VIEWSTATE'] = $viewstate;
    $postfields['__EVENTVALIDATION'] = $eventvalidation;
    $postfields['ctl00$cphContent$ctl00$Button1'] = 'Agree';
    #$postfields['ctl00$ctMain1$chkAgree$ctl02'] = 'on';

    $curl = curl_init($terms_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postfields);
    curl_setopt($curl, CURLOPT_HEADER, TRUE);
    $terms_response = curl_exec($curl);
    curl_close($curl);
    // get cookie
    // Please imporve it, it changes ASP.NET to ASP_NET and Path is missing etc
    // Set-Cookie: ASP.NET_SessionId=bz3jprrptbflxgzwes3mtse4; path=/; HttpOnly
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $terms_response, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }
    return $cookies;
}

$cookies = accept_terms_get_cookies($terms_url, $cookie_file);

$url_base = "http://daenquiry.hurstville.nsw.gov.au/masterviewui/Modules/applicationmaster/";
$da_page = $url_base . "default.aspx?page=found&1=thisweek&4a=DA%27,%27S96Mods%27,%27Mods%27,%27Reviews&6=F";
$da_page = $url_base . "default.aspx?page=found&1=thismonth&4a=DA%27,%27S96Mods%27,%27Mods%27,%27Reviews&6=F";        # Use this URL to get 'This Month' submitted DA, also to test pagination
#$da_page = $url_base . "default.aspx?page=found&1=lastmonth&4a=DA%27,%27S96Mods%27,%27Mods%27,%27Reviews&6=F";        # Use this URL to get 'Last Month' submitted DA, also to test pagination
$comment_base = "mailto:hccmail@hurstville.nsw.gov.au?subject=Development Application Enquiry: ";


$request = array(
    'http'    => array(
    'header'  => 'Cookie: ASP.NET_SessionId=' .$cookies['ASP_NET_SessionId']. '; path=/; HttpOnly\r\n'
    ));
$context = stream_context_create($request);
$dom = file_get_html($da_page, false, $context);

### Collect all 'hidden' inputs, plus add the current $eventtarget
### $eventtarget is coming from the 'pages' section of the HTML
function buildformdata($dom, $eventtarget) {
    $a = array();
    foreach ($dom->find("input[type=hidden]") as $input) {
        if ($input->value === FALSE) {
            $a = array_merge($a, array($input->name => ""));
        } else {
            $a = array_merge($a, array($input->name => $input->value));
        }
    }
    $a = array_merge($a, array('__EVENTTARGET' => $eventtarget));
    $a = array_merge($a, array('__EVENTARGUMENT' => ''));
    
    return $a;
}

# By default, assume it is single page
$dataset  = $dom->find("tr[class=rgRow], tr[class=rgAltRow]");
$NumPages = count($dom->find('div[class=rgWrap rgNumPart] a'));
if ($NumPages === 0) { $NumPages = 1; }

for ($i = 1; $i <= $NumPages; $i++) {
    # If more than a single page, fetch the page
    if ($NumPages > 1) {
        $eventtarget = substr($dom->find('div[class=rgWrap rgNumPart] a',$i-1)->href, 25, 61);
        $request = array(
            'http'    => array(
            'method'  => 'POST',
            'header'  => 'Cookie: ASP.NET_SessionId=' .$cookies['ASP_NET_SessionId']. '; path=/; HttpOnly\r\n' .
                         'Content-Type: application/x-www-form-urlencoded\r\n',
            'content' => http_build_query(buildformdata($dom, $eventtarget))));
        $context = stream_context_create($request);
        $html = file_get_html($da_page, false, $context);
        
        $dataset = $html->find("tr[class=rgRow], tr[class=rgAltRow]");
        echo "Sorting out page $i of $NumPages\r\n";
    }

    # The usual, look for the data set and if needed, save it
    foreach ($dataset as $record) {
        # Slow way to transform the date but it works
        $date_received = explode(' ', (trim($record->children(2)->plaintext)), 2);
        $date_received = explode('/', $date_received[0]);
        $date_received = "$date_received[2]-$date_received[1]-$date_received[0]";

        # Prep a bit more, ready to add these to the array
        $tempstr = explode('<br/>', $record->children(3)->innertext);
        
        # Put all information in an array
        $application = array (
            'council_reference' => trim($record->children(1)->plaintext),
            'address'           => trim($record->children(3)->children(0)->plaintext) . ", NSW  AUSTRALIA",
            'description'       => preg_replace('/\s+/', ' ', $tempstr[1]),
            'info_url'          => $url_base . trim($record->find('a',0)->href),
            'comment_url'       => $comment_base . trim($record->children(1)->plaintext) . '&Body=',
            'date_scraped'      => date('Y-m-d'),
            'date_received'     => $date_received
        );

        # Check if record exist, if not, INSERT, else do nothing
        $existingRecords = scraperwiki::select("* from data where `council_reference`='" . $application['council_reference'] . "'");
        if (count($existingRecords) == 0) {
            print ("Saving record " . $application['council_reference'] . "\n");
            # print_r ($application);
            scraperwiki::save(array('council_reference'), $application);
        } else {
            print ("Skipping already saved record " . $application['council_reference'] . "\n");
        }
    }
}



?>
