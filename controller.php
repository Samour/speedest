<?php

session_start();

header("Content-Type: application/json");

$response = Array();

function start_next_test($state) {
    if (!isset($state->testqueue) || sizeof($state->testqueue)==0) {return;}
    $state->runningid = array_shift($state->testqueue); // Serve next client on queue
    // Open process to execute the test - results will be printed to stdout
    $process = proc_open("../py/speedtest_cli.py", Array(1=>Array("pipe","w"), 2=>Array("pipe", "w")), $pipes);
    if (!is_resource($process)) { // Ensure process opened successfully
        unset($state->runningid);
        unset($state->prochandle);
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        if ($state->runningid == session_id()) { // If current client ordered the failing test
            $response["response"] = "SERVER_PROC_FAIL";
            $response["message"] = "Failed to start test";
            die(json_encode($response));
        }
        start_next_test($state);
        return; // add more code here
    }
    // Store handles for running test
    $state->prochandle = $process; // Store handle to running python script
    $state->pipes = $pipes; // Store handle to stdout & sderr of python script
    $state->results = Array("raw" => "", "stderr" => ""); // Record of results
    $state->linesread = 0; // Counter for parsing stdout
    $state->rollover = ""; // store part of line if stdout read before an entire line printed
    unset($state->writelock);
    file_put_contents("var/state", serialize($state));
}

/***
 * Read lines from stdout (passed) and format to JSON for both transmission to client and storage for final test result
 * @param    $state     Pointer to state object
 *                      JSON formated as follows:
 *                      "results": {
 *                          "ipaddr": "127.0.0.1",
 *                          "isp": "Telstra",
 *                          "testserver": "Internode",
 *                          "location": "Melbourne",
 *                          "distance": "4.9km",
 *                          "pingtime": "9.7ms",
 *                          "downsp": "20.37Mbit/s",
 *                          "upsp": "4.86Mbit/s",
 *                          "stderr": "Data from python script"
 *                      }
 * @return    Array     Array to be returned to client
 *                      "data": {
 *                          "ipaddr": "127.0.0.1",
 *                          "isp": "Telstra",
 *                          "testserver": "Internode",
 *                          "location": "Melbourne",
 *                          "distance": "4.9km",
 *                          "pingtime": "9.7ms",
 *                          "downsp": "20.37Mbit/s",
 *                          "downdots": 3,
 *                          "upsp": "4.86Mbit/s",
 *                          "updots": 5
 *                          }
 *                      Note: "*dots" fields represent number of dots added
 *                      since last update call. Up to client (JS) to translate
 *                      into progress %age
 */
function add_data($state) {
    $data = Array("data" => Array(), "errors" => Array());
    $stderr = stream_get_contents($state->pipes[1]);
    $state->results["stderr"] .= $stderr;
    $stdout = stream_get_contents($state->pipes[0]);
    $state->results["raw"] .= $stdout;
    $stdout = $state->rollover.$stdout;
    $state->rollover = "";
    $lines = explode("\n", $stdout);
    if (strlen($sdout)>0 && substr($stdout, -1)!="\n") {
        $state->rollover = array_pop($lines);
    }
    foreach ($lines as $line) {
        switch ($state->linesread) {
            case 0: case 1: break;
            case 2:
                $comps = explode(" ", $line);
                if (sizeof($comps)<4 || strcmp($comps[0], "Testing")!=0 || strcmp($comps[1], "from")!=0) {
                    $data["errors"][] = Array("name" => "PARSE_ERROR", "message" => "Failed to parse IP/ISP");
                    break;
                }
                $data["data"]["isp"] = $comps[2];
                $comps2 = explode(")", $comps[3]);
                $data["data"]["ipaddr"] = trim($comps2[0], "(");
                $state->results["isp"] = $data["data"]["isp"];
                $state->results["ipaddr"] = $data["data"]["ipaddr"];
                break;
            case 3: break;
            case 4:
                $comps = explode(" ", $line);
                if (sizeof($comps)<7 || strcmp($comps[0], "Hosted")!=0 || strcmp($comps[1], "by")!=0) {
                    $data["errors"][] = Array("name" => "PARSE_ERROR", "message" => "Failed to parse Test server & Ping time");
                    break;
                }
                $data["data"]["testserver"] = $comps[2];
                $data["data"]["location"] = trim($comps[3], "()");
                $data["data"]["distance"] = trim(trim($comps[4], ":"), "[]");
                $data["data"]["pingtime"] = $comps[5]." ".$comps[6];
                $state->results["testserver"] = $data["data"]["testserver"];
                $state->results["location"] = $data["data"]["location"];
                $state->results["distance"] = $data["data"]["distance"];
                $state->results["pingtime"] = $data["data"]["pingtime"];
                break;
            case 5:
                $pos = strpos($line, ".");
                if ($pos !== false) {
                    $data["data"]["downdots"] = strlen($line)-$pos;
                }
                break;
            case 6:
                $comps = explode(" ", $line);
                if (sizeof($comps)<3 || strcmp($comps[0], "Download:")!=0) {
                    $data["errors"][] = Array("name" => "PARSE_ERROR", "message" => "Failed to parse Download speed");
                    break;
                }
                $data["data"]["downsp"] = $comps[1]." ".$comps[2];
                $state->results["downsp"] = $data["data"]["downsp"];
                break;
            case 7:
                $pos = strpos($line, ".");
                if ($pos !== false) {
                    $data["data"]["updots"] = strlen($line)-$pos;
                }
                break;
            case 8:
                $comps = explode(" ", $line);
                if (sizeof($comps)<3 || strcmp($comps[0], "Upload:")!=0) {
                    $data["errors"][] = Array("name" => "PARSE_ERROR", "message" => "Failed to parse Upload speed");
                    break;
                }
                $data["data"]["upsp"] = $comps[1]." ".$comps[2];
                $state->results["upsp"] = $data["data"]["upsp"];
                break;
            default: break;
        }
        $state->linesread++;
    }
    if ($state->linesread == 5) {
        $pos = strpos($state->rollover, ".");
        if ($pos !== false) {
            $data["data"]["downdots"] = strlen($line)-$pos;
        }
        $state->rollover = "";
    }
    if ($state->linesread == 7) {
        $pos = strpos($state->rollover, ".");
        if ($pos !== false) {
            $data["data"]["updots"] = strlen($line)-$pos;
        }
        $state->rollover = "";
    }
    return $data;
}

function store_test($state) {
    $passback = add_data($state);
    file_put_contents("var/".time().".json", json_encode($state->results));
    if (!isset($state->stored)) {$state->stored = Array();}
    $state->stored[$state->runningid] = $state->results;
    proc_terminate($state->prochandle);
    fclose($state->pipes[0]);
    fclose($state->pipes[1]);
    proc_close($state->prochandle);
    unset($state->runningid);
    if (sizeof($state->stored) > 20) {
        reset($state->stored);
        unset($state->stored[key($state->stored)]);
    }
    return $passback;
}

if (!isset($_GET["action"])) { // An action must be specified
    $response["response"] = "ACTION_MISSING";
    $response["message"] = "Please specify an action to perform";
    $response["actions"] = Array("start", "update", "retrieve", "cancel", "clear");
    die(json_encode($response));
}

$action = $_GET["action"];

if (strcmp($action, "start") == 0) {
    // Variables are stored in a universal object
    // Open object & lock for writing
    // If already locked, wait to become unlocked
    $state = unserialize(file_get_contents("var/state"));
    while (isset($state->writelock) && $state->writelock != session_id()) {
        time_nanosleep(5000000);
        $state = unserialize(file_get_contents("var/state"));
    }
    $state->writelock = session_id();
    file_put_contents("var/state", serialize($state));
    $response = Array();
    $proc_status = isset($state->prochandle)?proc_get_status($state->prochandle):false;
    if (isset($state->runningid) && $proc_status!==false && $proc_status["running"]) { // If there is already a test running
        if ($state->runningid == session_id()) { // And current client started the test
            // Tell them they have a test and do nothing
            unset($state->writelock);
            file_put_contents("var/state", serialize($state));
            $response["response"] = "TEST_EXISTS";
            $response["message"] = "You have already started a test. Use action=update to view progress of this test.";
            die(json_encode($response));
        } // Or if it is someone else's test
        // add request for new test to pending queue
        if (!isset($state->testqueue)) {$state->testqueue = Array();}
        if (in_array(session_id(), $state->testqueue)) { // If client aleady in test for queue
            // Tell them they are in the queue and do nothing
            unset($state->writelock);
            file_put_contents("var/state", serialize($state));
            $response["response"] = "TEST_PENDING";
            $response["queuepos"] = array_search(session_id(), $state->testqueue)+1;
            $response["message"] = "Your test is already in the queue.";
            die(json_encode($response));
        }
        $state->testqueue[] = session_id();
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        $response["response"] = "TEST_QUEUED";
        $response["queuepos"] = sizeof($state->testqueue);
        $response["message"] = "Test is already running on host. Your test has been queued.";
        die(json_encode($response));
    }
    // ADD call to start_next_test()
    if (isset($state->runningid)) { // If a test has completed but is still in state
        // Store test for their later retrieval
        store_test($state);
    }
    // Enqueue client's test and run test at top of queue
    if (!isset($state->testqueue)) {$state->testqueue = Array();}
    $state->testqueue[] = session_id();
    start_next_test($state);
    if ($state->runningid == session_id()) {
        // Tell client test has started
        $response["response"] = "TEST_STARTED";
    } else {
        // Tell client test has been queued
        $response["response"] = "TEST_QUEUED";
        $response["queuepos"] = sizeof($state->testqueue);
        $response["message"] = "Test is already running on host. Your test has been queued.";
    }
    exit(json_encode($response));
} else if (strcmp($action, "update") == 0) {
    $state = unserialize(file_get_contents("var/state"));
    while (isset($state->writelock) && $state->writelock != session_id()) {
        time_nanosleep(5000000);
        $state = unserialize(file_get_contents("var/state"));
    }
    $state->writelock = session_id();
    file_put_contents("var/state", serialize($state));
    $response = Array();
    if (!isset($state->runningid)) { // Check that a test is running
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        $response["response"] = "TEST_ABSENT";
        $response["message"] = "No test currently running. Use action=start to begin a new test.";
        die (json_encode($response));
    }
    // Check process status
    $status = proc_get_status($state->prochandle);
    if ($status===false || !$status["running"]) { // An error occurred in checking status OR there is no running session
        if ($state->runningid == session_id()) { // If client was running most recent test
            if ($status === false) { // If their test process failed
                $response["response"] = "SERVER_PROC_FAIL";
                $response["message"] = "Server failed to find process status";
            } else {
                $data = store_test($state);
                start_next_test($state);
                $response["response"] = "TEST_COMPLETE";
                $response["data"] = $data["data"];
                $response["errors"] = $data["errors"];
            }
            unset($state->writelock);
            file_put_contents("var/state", serialize($state));
            die(json_encode($response));
        }
        store_test($state);
        start_next_test($state);
    }
    if ($state->runningid != session_id()) { // Check that client owns running test
        // Advise client if they have a test queued
        if (isset($state->testqueue) && in_array(session_id(), $state->testqueue)) {
            $response["response"] = "TEST_QUEUED";
            $response["queuepos"] = array_search(session_id(), $state->testqueue)+1;
            $response["message"] = "A test is already running. Your test is in the queue.";
        } else {
            $response["response"] = "TEST_ABSENT";
            $response["message"] = "No test currently running. Use action=start to begin a new test.";
        }
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        die(json_encode($response));
    }
    $data = add_data($state);
    $response["response"] = "TEST_RUNNING";
    $response["data"] = $data["data"];
    $response["errors"] = $data["errors"];
    unset($state->writelock);
    file_put_contents("var/state", serialize($state));
    exit(json_encode($response));
} else if (strcmp($action, "retrieve") == 0) {
    $state = unserialize(file_get_contents("var/state"));
    $response = Array();
    if (isset($state->stored) && key_exists(session_id(), $state->stored)) {
        $response["response"] = "TEST_FOUND";
        $response["results"] = $state->stored[session_id()];
    } else {
        $response["response"] = "TEST_NOT_FOUND";
        $response["message"] = "You have not run and completed any tests with the current client";
    }
    exit(json_encode($response));
} else if (strcmp($action, "cancel") == 0) {
    $state = unserialize(file_get_contents("var/state"));
    while (isset($state->writelock) && $state->writelock != session_id()) {
        time_nanosleep(5000000);
        $state = unserialize(file_get_contents("var/state"));
    }
    $state->writelock = session_id();
    file_put_contents("var/state", serialize($state));
    $response = Array();
    if (!isset($state->runningid)) { // Check that a test is running
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        $response["response"] = "TEST_ABSENT";
        $response["message"] = "No test currently running. Use action=start to begin a new test.";
        die (json_encode($response));
    }
    if ($state->runningid != session_id()) {
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        $response["response"] = "OWNER_ERR";
        $response["message"] = "You cannot cancel a test you did not start";
        die(json_encode($response));
    }
    $status = proc_get_status($state->prochandle);
    if ($status === false) {
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        $response["response"] = "SERVER_PROC_FAIL";
        $response["message"] = "Server failed to find process status";
        die(json_encode($response));
    }
    if (!$status["running"]) {
        $response["response"] = "TEST_COMPLETE";
    } else {
        $response["response"] = "TEST_CANCELLED";
    }
    $data = store_test($state);
    start_next_test($state);
    if (isset($_GET["retrieve-data"]) && $_GET["retrieve-data"]==true) {
        $response["data"] = $data["data"];
        $response["errors"] = $data["errors"];
    }
    if (isset($_GET["retrieve-test"]) && $_GET["retrieve-test"]==true) {
        $response = Array();
        if (isset($state->stored) && key_exists(session_id(), $state->stored)) {
            $response["results"] = $state->stored[session_id()];
        } else {
            $response["results"] = null;
        }
    }
    if (isset($_GET["clear"]) && $_GET["clear"]==true) {
        unset($state->stored[session_id()]);
    }
    unset($state->writelock);
    file_put_contents("var/state", serialize($state));
    exit(json_encode($response));
} else if (strcmp($action, "clear") == 0) {
    $state = unserialize(file_get_contents("var/state"));
    while (isset($state->writelock) && $state->writelock != session_id()) {
        time_nanosleep(5000000);
        $state = unserialize(file_get_contents("var/state"));
    }
    $state->writelock = session_id();
    file_put_contents("var/state", serialize($state));
    $response = Array();
    if (!isset($state->stored) || !key_exists(session_id(), $state->stored)) {
        unset($state->writelock);
        file_put_contents("var/state", serialize($state));
        $response["response"] = "TEST_NOT_FOUND";
        $response["message"] = "You have not run and completed any tests with the current client";
        die(json_encode($response));
    }
    $response["response"] = "TEST_CLEARED";
    if (isset($_GET["retrieve"]) && $_GET["retrieve"]==true) {
        $response["results"] = $state->stored[session_id()];
    }
    unset($state->stored[session_id()]); // does result remain in response after unsetting from state?
    unset($state->writelock);
    file_put_contents("var/state", serialize($state));
    exit(json_encode($response));
} else {
    $response["response"] = "ACTION_INCORRECT";
    $response["message"] = "Please specify an action to perform";
    $response["actions"] = Array("start", "update", "retrieve", "cancel", "clear");
    die($response);
}