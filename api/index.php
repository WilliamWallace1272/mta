<?php
require '../vendor/autoload.php';
require_once("../inc/common.php");
require_once("api_lib.php");

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \JsonSchema\Validator as JsonValidator;

$config['displayErrorDetails'] = true;
$config['addContentLengthHeader'] = false;
$config['determineRouteBeforeAppMiddleware'] = true;
$container = new \Slim\Container;
$container['dataMgr'] = $dataMgr;
$container['settings']['displayErrorDetails'] = true;
$validationPaths = ['peermatch_get' => 'json/peermatch/get/', 
'peermatch_create' => 'json/peermatch/create/'];
$container['validationPaths'] = $validationPaths;
$app = new \Slim\App($container); //$container); 
$app->add(new \Slim\Middleware\HttpBasicAuthentication([
    "path" => "/", /* or ["/admin", "/api"] */
    "realm" => "Protected",
    "secure" => false,
    "users" => [
        "root" => "t00r",
        "user" => "passw0rd"
    ]]));


function decode_json_throw_errors($inputString) {
	$json_body = json_decode($inputString);
	switch (json_last_error()) {
	case JSON_ERROR_NONE:
		break;
	case JSON_ERROR_DEPTH:
		throw new Exception('JSON Maximum stack depth exceeded');
		break;
	case JSON_ERROR_STATE_MISMATCH:
		throw new Exception('Underflow or the modes mismatch');
		break;
	case JSON_ERROR_CTRL_CHAR:
		throw new Exception('Unexpected control character found');
		break;
	case JSON_ERROR_SYNTAX:
		throw new Exception('Syntax error, malformed JSON');
		break;
	case JSON_ERROR_UTF8:
		throw new Exception('Malformed UTF-8 characters, possibly incorrectly encoded');
		break;
	default:
		throw new Exception('Unknown Error');
		break;
	}
	return $json_body;
}

function getSchema($route_name) { 
	list($data_type, $endpoint) = explode(":", $route_name, 2);
	$full_schema = decode_json_throw_errors(file_get_contents('./json/' . $data_type . '.json'));
	$request_schema ='';
	$response_schema = '';
	foreach ($full_schema->links as $endpoint_schema) { 
		//TODO: this is ugly
		if (strcmp(str_replace('"','',json_encode($endpoint_schema->title)), $endpoint) == 0) { 
		  $request_schema = $endpoint_schema->schema;
		  $response_schema = $endpoint_schema->targetSchema;
		}
	}
	return array($request_schema, $response_schema);
}

$jsonDecodeMW = function ($request, $response, $next) {
	$json_body = decode_json_throw_errors($request->getBody());
	$request = $request->withAttribute('requestDecodedJson', $json_body);
	$response = $next($request, $response);
	return $response;	
};

$jsonvalidateMW = function ($request, $response, $next) {
	$json_body = $request->getAttribute('requestDecodedJson');
	$route_name = $request->getAttribute('route')->getName();
	//TODO: maybe surround this with a try/catch
	//$request_schema = decode_json_throw_errors(file_get_contents('./json/' . $route_name . 'request.json'));
	list($request_schema, $response_schema) = getSchema($route_name);
	$validator = new League\JsonGuard\Validator($json_body, $request_schema);
	if ($validator->fails()) {
		print_r($validator->errors());
		return NULL;
	}
	$next($request, $response);
	//$response_schema = decode_json_throw_errors(file_get_contents('./json/' . $route_name . 'response.json'));
	
	if(json_decode($response->getBody())){
		$validator = new League\JsonGuard\Validator(json_decode($response->getBody()), $response_schema);
		if ($validator->fails()) {
			print_r($validator->errors());
			return NULL;
		}
	}
	return $response;
};


################# COURSE ########################

$app->get('/course/get', function (Request $request, Response $response) use ($dataMgr) {
	$json_body = json_decode($request->getBody());
    $params = (array) $json_body;
    if (isset($params['courseID'])) {	
		$courseID = new CourseID($params['courseID']);
		$dataMgr->setCourseFromID($courseID);
		$returnVal = $dataMgr->getCourseInfo($courseID);
	}
	else {
		$returnVal = $dataMgr->getCourses();
	}
	return $response->withJson($returnVal);
});

$app->post('/course/create', function (Request $request, Response $response) use ($dataMgr) {
    # needs JSON validation
    //TODO ADD ERROR CATCHING
    $params = $request->getBody();
	$params = json_decode($params, true);
 
	$dataMgr->createCourse($params['name'], $params['displayName'], $params['authType'], $params['registrationType'], isset_bool($params['browsable']));
    return $response;
 });
  
$app->post('/course/update', function (Request $request, Response $response) use ($dataMgr) {
    # needs JSON validation
    //TODO ADD ERROR CATCHING
	$params = $request->getBody();
    $params = json_decode($params, true);
    $courseID = new CourseID($params['courseID']);
    $dataMgr->setCourseFromID($courseID);
    $courseInfo = (array) $dataMgr->getCourseInfo($courseID);
 
    foreach($params as $key => $value) {
        $temp = $params[$key];
        $courseInfo[$key] = $temp;
    }
    $dataMgr->setCourseInfo($courseID, $courseInfo['name'], $courseInfo['displayName'], $courseInfo['authType'], $courseInfo['registrationType'], isset_bool($courseInfo['browsable']));
	 
    return $response->withJson($dataMgr->getCourseInfo($courseID));
 });

$app->post('/course/delete', function (Request $request, Response $response) use ($dataMgr) {
	# needs JSON validation
    //TODO ADD ERROR CATCHING
    $params = $request->getBody();
    $params = json_decode($params, true);
    $courseID = new CourseID($params['courseID']);
    $dataMgr->setCourseFromID($courseID);

    $dataMgr->deleteCourse($courseID);
    return $response;
});

####################### USERS #################################

$app->post('/user/create', function (Request $request, Response $response) use ($dataMgr) {
    //TODO ADD ERROR CATCHING
    $params = $request->getBody();
    $params = json_decode($params, true);
    $courseID = new CourseID($params['courseID']);
    $dataMgr->setCourseFromID($courseID);
	$authMgr = $dataMgr->createAuthManager();

	foreach($params['users'] as $user) {
		# markingLoad?
		$dataMgr->addUser($user['username'], $user['firstName'], $user['lastName'], $user['studentID'], $user['userType']);
		$authMgr->addUserAuthentication($user['username'], $user['password']);
	}
 
    return $response->withJson($dataMgr->getUsers());
});

$app->post('/user/update', function (Request $request, Response $response) use ($dataMgr) {
    //TODO ADD ERROR CATCHING
    $params = $request->getBody();
	$params = json_decode($params, true);
    $courseID = new CourseID($params['courseID']);
    $dataMgr->setCourseFromID($courseID);
	
	foreach ($params['users'] as $user) {
		if($dataMgr->isUserByName($user['username'])) {
			$user_id = $dataMgr->getUserID($user['username']);
			$student_info = (array) $dataMgr->getUserInfo($user_id);
			foreach ($user as $key => $value) {
				if($key != 'username' && !empty($user[$key])) {
					$student_info[$key] = $user[$key];	
				}
			}
			$dataMgr->updateUser($user_id, $student_info['username'], $student_info['firstName'], $student_info['lastName'], $student_info['studentID'], $student_info['userType']);
		}
	}
 
    return $response->withJson($dataMgr->getUsers());
});


$app->post('/user/delete', function (Request $request, Response $response) use ($dataMgr) {
    //TODO ADD ERROR CATCHING
    $params = $request->getBody();
	$params = json_decode($params, true);
    $courseID = new CourseID($params['courseID']);
    $dataMgr->setCourseFromID($courseID);

	for($x = 0; $x < count($params['users']); $x++) {
		if($dataMgr->isUserByName($params['users'][$x])) {
			$user_id = $dataMgr->getUserID($params['users'][$x]);
			$dataMgr->dropUser($user_id);
		}
	}
    return $response->withJson($dataMgr->getUsers());
});

$app->get('/user/get', function (Request $request, Response $response) use ($dataMgr) {
	$json_body = json_decode($request->getBody());
    $params = (array) $json_body;
	$student_info = array();
	$courseID = new CourseID($params['courseID']);
	$dataMgr->setCourseFromID($courseID);
	
	if (isset($params['users'])) {
		for($x = 0; $x < count($params['users']); $x++) {
			if($dataMgr->isUserByName($params['users'][$x])) {
				$user_id = $dataMgr->getUserID($params['users'][$x]);
				$student_info[] = $dataMgr->getUserInfo($user_id);
			}
		}
		$return_val	= $student_info;
	}
	else {
		$return_val = $dataMgr->getUsers();
	}
	return $response->withJson($return_val);
});


####################### ASSIGNMENTS ###########################

$app->get('/assignment/get', function (Request $request, Response $response) use ($dataMgr){


    $params = json_decode($request->getBody(), true);
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    
    if ($params["assignmentIDs"] == "all"){
    
        $assignments = $dataMgr->getAssignments();
        $newResponse = $response->withJson($assignments);
        return $newResponse;
    }
    else {
        $assignment = array(); 
        foreach ($params["assignmentIDs"] as $id){
            $assignments[] = $dataMgr->getAssignment(new AssignmentID($id));
        }
        $newResponse = $response->withJson($assignments);
        return $newResponse;
    }

})->setName('assignment:get')->add($jsonvalidateMW)->add($jsonDecodeMW);

$app->post('/assignment/create', function (Request $request, Response $response) use ($dataMgr){
    
    $params = $request->getBody();
    $params = json_decode($params, true);
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    $assignment = create_assignment($params); 
})->setName('assignment:create')->add($jsonvalidateMW)->add($jsonDecodeMW);

$app->post('/assignment/update', function( Request $request, Response $response) use ($dataMgr){
    $json_params = $request->getBody();
    $params = json_decode($json_params,true); 
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    $assignment = update_assignment($params);
    
})->setName('assignment:update')->add($jsonvalidateMW)->add($jsonDecodeMW);



#################### PEERREVIEWS ######################333
$app->get('/rubric/get',function (Request $request, Response $response) use ($dataMgr){

    $params = json_decode($request->getBody(),true);

    $assignment = $dataMgr->getAssignment(new AssignmentID($params['assignmentID']));
    $questions = $assignment->getReviewQuestions($assignment);
    $newResponse = $response->withJson($questions);
    return $newResponse;
});

$app->post('/rubric/update',function(Request $request, Response $response) use($dataMgr){
    //TODO Make this a patch
    $params = $request->getBody();
    $params = json_decode($params,true);
    $assignment = $dataMgr->getAssignment(new AssignmentID($params["assignmentID"]));
    update_radio_question($assignment, $params); 
    $response->getBody()->write("finished");
});

$app->post('/rubric/create',function(Request $request, Response $response) use ($dataMgr){
    
    $params = json_decode($request->getBody(),true);
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    #actually don't think you even need the course name, but not 100% about the whole mta system so its here
    unset($params['courseID']); #get rid of it cause not needed for the rubric
    $assignment = $dataMgr->getAssignment(new AssignmentID($params['assignmentID'])); 
    create_radio_question($assignment, $params);
});

/*$app->get('/peerreviewscores/get', function(Request $request, Response $response) use($dataMgr){
    $params = json_decode($request->getBody(),true);
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    $assignment = $dataMgr->getAssignment(new AssignmentID($params['assignmentID']));
    $review = $assignment->getReview(new MatchID($params['matchID']));
    $newResponse = $response->withJson($review);
    return $newResponse;
});*/

$app->get('/peerreviewscores/get', function(Request $request, Response $response) use($dataMgr){
    $params = json_decode($request->getBody(),true);
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    $assignment = $dataMgr->getAssignment(new AssignmentID($params['assignmentID']));
    # $review = $assignment->getReview(new MatchID($params['matchID']));
	$db = $dataMgr->getDatabase();
    $submissionIDs = getSubmissionIDsForAssignment($db, new AssignmentID($params['assignmentID']));
	print_r($submissionIDs[0]);
	
	$reviews = array();
	foreach($submissionIDs as $id) {
		$reviews[$id] = $assignment->getReviewsForSubmission(new SubmissionID($id));
	}
    $newResponse = $response->withJson($reviews);
    return $newResponse;
});

############################ GRADES #############################

$app->post('/grades/create', function(Request $request, Response $response) use($dataMgr){
    $params = json_decode($request->getBody(),true);
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    $assignment = $dataMgr->getAssignment(new AssignmentID($params['assignmentID']));
    
	foreach($params['grades'] as $value) {
		$assignment->saveSubmissionMark(new Mark($value[1], null, true), new SubmissionID($value[0]));
	}
	
    return $response;
});

############################ STUDENT SIDE TESTING ###############
$app->post('/makesubmissions', function (Request $request, Response $response) use
    ($dataMgr){#takes in course name and assignment id
        $params = $request->getBody();
        $params = json_decode($params,true);
        mock_submissions($params["courseID"], $params["assignmentID"]); 
});

$app->post('/peerreviewscores/create', function(Request $request, Response $response) use($dataMgr){

    $params = json_decode($request->getBody(),true);
    $dataMgr->setCourseFromID(new CourseID($params['courseID']));
    $assignment = $dataMgr->getAssignment(new AssignmentID($params['assignmentID']));
    make_peer_review($assignment, $params);
});

$app->get('/getcourseidfromname', function (Request $request, Response $response) use ($dataMgr) {
	$params = $request->getBody();
    $params = json_decode($params,true);
	$dataMgr->setCourseFromName($params['courseName']);
	return $response->withJson($dataMgr->courseID);
});

$app->get('/peermatch/get/',  function (Request $request, Response $response) use ($dataMgr) {
	$json_body = $request->getAttribute('requestDecodedJson');
	$dataMgr = $this->dataMgr;
	$assignmentID = $json_body->assignmentID;
	//TODO: handle exceptions and errors around the database calls
	$db = $dataMgr->getDatabase();
	$sh = $db->prepare("SELECT  peer_review_assignment_submissions.submissionID, peer_review_assignment_matches.reviewerID
       			FROM peer_review_assignment_submissions JOIN peer_review_assignment_matches ON
       		peer_review_assignment_matches.submissionID = peer_review_assignment_submissions.submissionID
       		WHERE peer_review_assignment_submissions.assignmentID = ?
       		ORDER BY peer_review_assignment_submissions.submissionID");
	$sh->execute(array($assignmentID));
	//Note, authorID is authorID for submission, 
	//reviewerID is person matched to review submission, by authorID, on assignmentID
	$peerMatches = array();
	while($res = $sh->fetch()) {
					$peerMatches[] = array((int)$res->submissionID, (int)$res->reviewerID);
	}
	$return_array['peerMatches'] = $peerMatches;
	$return_array['assignmentID'] = $assignmentID;
	$newResponse = $response->withJson($return_array);
	return $newResponse;
})->add($jsonvalidateMW)->add($jsonDecodeMW)->setName('peermatch:get');

$app->post('/peermatch/test', function (Request $request, Response $response) use ($dataMgr) {
	$json_body = $request->getAttribute('requestDecodedJson');
 	$peerMatches = $json_body->peerMatches;
	print_r($peerMatches);
	foreach ($peerMatches as $peerMatch) { 
		//verify that submissionID is valid, userID is valid
	}
})->add($jsonvalidateMW)->add($jsonDecodeMW)->setName('peermatch:create_bulk');

$app->post('/peermatch/create', function (Request $request, Response $response) use ($dataMgr) {
	$json_body = $request->getAttribute('requestDecodedJson');
	$db = $dataMgr->getDatabase();
	$assignmentID = $json_body->assignmentID;
	$peerMatch = $json_body->peerMatch;
	$submissionID = $peerMatch->submissionID;  
	$reviewerID = $peerMatch->reviewerID;
	insertSinglePeerMatch($db, $submissionID, $reviewerID, $assignmentID);
	return $response;
})->add($jsonvalidateMW)->add($jsonDecodeMW)->setName('peermatch:create');

$app->post('/peermatch/create_bulk', function (Request $request, Response $response) use ($dataMgr) {
	$json_body = $request->getAttribute('requestDecodedJson');
	$db = $dataMgr->getDatabase();
	$assignmentID = $json_body->assignmentID;
	$peerMatches = $json_body->peerMatches;
	foreach ($peerMatches as $peerMatch) { 
		$submissionID = $peerMatch->submissionID;  
		$reviewerID = $peerMatch->reviewerID;
		insertSinglePeerMatch($db, $submissionID, $reviewerID);
	}
})->add($jsonvalidateMW)->add($jsonDecodeMW)->setName('peermatch:create_bulk');

$app->post('/peermatch/delete_all', function (Request $request, Response $response) use ($dataMgr) {
	$json_body = $request->getAttribute('requestDecodedJson');
	$db = $dataMgr->getDatabase();
	$assignmentID = $json_body->assignmentID;
	$db->beginTransaction();
	//$getAllMatches = $db->prepare("SELECT * from PEER_REVIEW_ASSIGNMENT_MATCHES where assignmentID = ?");

	$deleteAllMatches = $db->prepare("DELETE FROM peer_review_assignment_matches
			WHERE submissionID in (
				SELECT peer_review_assignment_submissions.submissionID 
				FROM peer_review_assignment_submissions 
				INNER JOIN peer_review_assignment_matches ON (peer_review_assignment_matches.submissionID = peer_review_assignment_submissions.submissionID) 
				WHERE peer_review_assignment_submissions.assignmentID = ?)");
	//$getAllMatches->execute(array($assignmentID));
	//$allMatches = $getAllMatches->fetchAll();
	$deleteAllMatches->execute(array($assignmentID));
	$db->commit();
})->setName('peermatch:delete_all')->add($jsonvalidateMW)->add($jsonDecodeMW);


$app->run();
