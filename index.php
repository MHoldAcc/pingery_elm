<?php
session_start();

// This file is the main file of pingery elm
// This file is used to show the log and execute the website checks
// IMPORTANT DO NOT CREATE ANY FUNCTIONS!!!

//region Default HTML Content
//Code to create HMTL page content
//Replaces default values in index.html
$HTML = file_get_contents('html/index.html', FILE_USE_INCLUDE_PATH);
$HTML = str_replace('[elm_Login_Text]', 'Manage Websites', $HTML);
$HTML = str_replace('[elm_Login_Link]', 'manage.php', $HTML);
$HTML = str_replace('[elm_Page_NavBar]', '<a class="active">Pingery elm</a>', $HTML);

//Replace this with log information!!!
$HTMLContent = '';
//endregion

//region Database Connection creation
// Include the config file to access the database connections if necessary
include("config.php");
// if variable $conn isn't set, create a database connection with the variables $elm_Settings_DSN, $elm_Settings_DbUser and $elm_Settings_DbPassword as defined in the config.php file
if (!isset($conn)){
    $conn = new PDO($elm_Settings_DSN, $elm_Settings_DbUser, $elm_Settings_DbPassword, array(
        PDO::ATTR_PERSISTENT => true
    ));
}
//endregion

//region Creates Database if not existing
// set database collation to UTF8
$sql = $conn->prepare("SET NAMES utf8;");
$sql->execute();
// check if database was created
$sql1 = $conn->prepare("SELECT * FROM elm_log;");
$sql1->execute();
// if tables in database  were created
if ($sql1->execute() == FALSE){
    // create table elm_websites
    $sql = $conn->prepare("CREATE TABLE `elm_websites` (
        `websitesId` int(11) NOT NULL AUTO_INCREMENT,
        `Name` varchar(255) NOT NULL,
        `URL` varchar(255) NOT NULL,
        `Created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `Updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`websitesId`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    $sql->execute();
    // create table elm_log
    $sql = $conn->prepare("CREATE TABLE `elm_log` (
        `logId` int(11) NOT NULL AUTO_INCREMENT,
        `websitesFK` int(11),
        `Timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `Message` varchar(255) NOT NULL,
        `Success` BOOLEAN,
        `callerIP` varchar(255) NOT NULL,
        PRIMARY KEY (`logId`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    $sql->execute();
}
//endregion

//region Pings Websites and adds log entries
$pages = array();
// get all entries in elm_websites
$sql = $conn->prepare("SELECT * FROM `elm_websites`;");
$sql->execute();
// push all results of previous query into array $pages
while ($row = $sql->fetch(PDO::FETCH_ASSOC)){
    array_push($pages, $row);
}

$MailJsHTMLContainer = '<script>
                (function(){
                   [elm_MailSend]
                })();
            </script>';
$MailJsSendMailTemplate = 'emailjs.send("default_service","[MailTemplate]",{URL: "[URL]", Name: "[Name]"});';
$MailSendContent = '';

// perform ping for all entries in the array $pages
foreach ($pages AS $page){
    $errNo = 0;
    $errStr = "";
    $URL = $page['URL'];

    //perform ping to provided URL
    $ping = @fsockopen($URL, 80, $errNo, $errStr, 30);

    // add the result of the ping to variable $message
    $message = "ERROR: $errNo -> $errStr";
    if ($ping) {
        //If Ping was successful
        $message = 'Website is online!';
        // see if the current entry pertaining to the URL is the same
        $sql = $conn->prepare("SELECT * FROM elm_log WHERE `websitesFK` = (SELECT `websitesId` FROM `elm_websites` WHERE `URL` = ?) AND `Message` = ?;");
        $sql->bindParam(1, $page['URL']);
        $sql->bindParam(2, $message);
        $sql->execute();
        if ($sql->rowCount() == 0) {
            // if the result differs write to elm_log
            $sql = $conn->prepare("UPDATE `elm_log`
                SET `Message` = ?, `Success`= TRUE, `Timestamp`= ?, `callerIP`= ?
                WHERE `websitesFK` = (SELECT `websitesId` FROM `elm_websites` WHERE `URL` = ?);");
            $sql->bindParam(1, $message);
            @$sql->bindParam(2, date("Y-m-d H:i:s"));
            $sql->bindParam(3, $_SERVER['REMOTE_ADDR']);
            $sql->bindParam(4, $page['URL']);
            $sql->execute();

            // send mail that the URL is now online
            $MailSendContent = $MailSendContent . str_replace('[MailTemplate]', 'pinger_elm_info', str_replace('[Name]', $page['Name'], str_replace('[URL]', $page['URL'], $MailJsSendMailTemplate)));
        }
    } else {
        //If Ping was not successful
        // see if the current entry pertaining to the URL is the same
        $sql = $conn->prepare("SELECT * FROM elm_log WHERE `websitesFK` = (SELECT `websitesId` FROM `elm_websites` WHERE `URL` = ?) AND `Message` = ?;");
        $sql->bindParam(1, $page['URL']);
        $sql->bindParam(2, $message);
        $sql->execute();
        if ($sql->rowCount() == 0){
            // if the result differs write to elm_log
            $sql = $conn->prepare("UPDATE `elm_log`
                SET `Message` = ?, `Success`= FALSE, `Timestamp`= ?, `callerIP`= ?
                WHERE `websitesFK` = (SELECT `websitesId` FROM `elm_websites` WHERE `URL` = ?);");
            $sql->bindParam(1, $message);
            @$sql->bindParam(2, date("Y-m-d H:i:s"));
            $sql->bindParam(3, $_SERVER['REMOTE_ADDR']);
            $sql->bindParam(4, $page['URL']);
            $sql->execute();

            // send mail that the URL is now online
            $MailSendContent = $MailSendContent . str_replace('[MailTemplate]', 'pinger_elm_alert', str_replace('[Name]', $page['Name'], str_replace('[URL]', $page['URL'], $MailJsSendMailTemplate)));
        }
    }
}
// variable $MailSendContent isn't empty send mail
if($MailSendContent != '')
    $HTMLContent = $HTMLContent . str_replace('[elm_MailSend]', $MailSendContent, $MailJsHTMLContainer);
//endregion

//region Gets all Websites for Log output
// get all required database entries required to output log
$sql = $conn->prepare("SELECT 
	elm_websites.Name as Name,
    elm_websites.URL as URL,
    elm_log.Timestamp as DateTime,
    elm_log.Success as Online,
    elm_log.Message as Message
FROM `elm_websites`
LEFT JOIN `elm_log`
ON elm_websites.websitesId = elm_log.websitesFK");
$sql->execute();

$sites = array();
// push all results of previous query into array $pages
while ($row = $sql->fetch(PDO::FETCH_ASSOC)){
    array_push($sites, $row);
}
//endregion

//region HTML Content creation
$HTMLContent = $HTMLContent . '<div style=" margin-left: 5%;  margin-bottom: 10%">
    <h2>Welcome to Pingery-Elm</h2>
    <br>

    <table style="width:115%" >
    <col width="15%">
    <col width="15%">
    <col width="15%">
    <col width="15%">
    <col width="30%">
        <tr>
            <th><h3>Website</h3></th>
            <th><h3>URL</h3></th>
            <th><h3>date / time</h3></th>
            <th><h3>online</h3></th>
            <th><h3>Message</h3></th>
        </tr>
        [elm_WebsiteOverview]
    </table>
</div>';

$elm_WebsiteOverview = '';
foreach($sites as $site) {
    $elm_WebsiteOverview = $elm_WebsiteOverview .
        '<tr>'.
            '<td style="text-align: left;">'.
                '<div style="color:black"><a style="color:black" target="_blank" href="https://' . $site['URL'] . '">'. $site['Name'] . '</a> &nbsp;&nbsp;'.

            '</td>'.

            '<td style="text-align: left;">'.
                $site['URL'].
            '</td>'.

            '<td style="text-align: center;">'.
                $site['DateTime'].
            '</td>'.

            '<td style="text-align: center;">'.
                ($site['Online'] == '1' ? 'Yes' : 'No').
            '</td>'.

            '<td style="text-align: center;">'.
            '<div style="color:'.($site['Online'] == '1' ? 'green' : 'red').'">'.
                $site['Message'].
            '</div></td>'.

        '</tr></div>';
}
$HTMLContent = str_replace('[elm_WebsiteOverview]', $elm_WebsiteOverview, $HTMLContent);

//Gives out the html
$HTML = str_replace('[elm_Page_Content]', $HTMLContent, $HTML);
echo $HTML;
//endregion

?>