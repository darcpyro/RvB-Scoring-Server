<?php
/*
 * This script manages the real time messaging service. Messages will be read from the message queue and send to all connected clients.
 * Messages are not buffered so if a client is not available when the message is sent then they will miss the message
 */

require('include/config.inc.php');

header("Content-Type: text/event-stream\n\n");

function pushStatusUpdate($type, $message, $messageId=-1){
    if(strlen(trim($message)) <= 1){
        return;
    }
    $data = array();
    $data['type'] = $type;
    $data['id'] = $messageId;
    $data['message'] = $message;
    echo "event: message\n";
    pushMessage(json_encode($data));
}

function pushMessage($message){
    echo "data: ".$message." \n\n";
    ob_flush();
    flush();
}

//If this is a new person then set their current message ID to the newest message so they don't get flooded with every message that has been sent
if(!isset($_SESSION['lastRTMSId'])){
    $_SESSION['lastRTMSId'] = dbSelect("rtmsMessageQueue",array("MAX(id)"), array(), false)[0]['MAX(id)'];
    if($_SESSION['lastRTMSId'] == null){
        $_SESSION['lastRTMSId'] = -1;
    }
}


//Check to see if this user has any background processes running
if(isset($_SESSION['BGProcess'])){
    if(is_array($_SESSION['BGProcess'])){
        if(count($_SESSION['BGProcess']) > 0) {
            for ($i = 0; $i < count($_SESSION['BGProcess']); $i++) {
                //Background process found
                $bg = unserialize($_SESSION['BGProcess'][$i]);
                if ($bg != null) {
                    //Get a list of new messages to display
                    $messages = $bg->getNewOutput();
                    //Sent all new messages as a notification
                    foreach ($messages as $message) {
                        pushStatusUpdate("message", $message);
                    }
                    //If the background process is finished then unset the session
                    if (!$bg->isRunning()) {
                        unset($_SESSION['BGProcess'][$i]);
                        //Check to see if this background process had a callback function
                        $callback = $bg->getCallback();
                        $callbackArgs = $bg->getCallbackArgs();
                        if ($callback != null) {
                            //Found a callback function, so call it
                            pushStatusUpdate("message", call_user_func_array($callback, $callbackArgs));
                        }
                    } else {
                        //Store the new version of the class in the session variable
                        $_SESSION['BGProcess'][$i] = serialize($bg);
                    }
                } else {
                    unset($_SESSION['BGProcess'][$i]);
                }
            }
            $_SESSION['BGProcess'] = array_values($_SESSION['BGProcess']);//Remove all the unset array values
        }
    }
}



$sql = "SELECT * FROM rtmsMessageQueue WHERE id > ".$_SESSION['lastRTMSId'];
$toSend = dbRaw($sql);//Get all messages from the last 30 seconds

if(count($toSend) == 0){
    exit();
}

foreach($toSend as $message){
    pushStatusUpdate($message['type'], $message['message'], $message['id']);
    $_SESSION['lastRTMSId'] = $message['id'];
}

