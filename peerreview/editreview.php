<?php
require_once("inc/common.php");
try
{
    $title .= " | Edit Review";
    $dataMgr->requireCourse();
    $authMgr->enforceLoggedIn();

    if(array_key_exists("close", $_GET))
        $closeOnDone = "&close=1";
    else
        $closeOnDone = "";

    $assignment = get_peerreview_assignment();

    $beforeReviewStart = $NOW < $assignment->reviewStartDate;
    $afterReviewStop   = $assignment->reviewStopDate < $NOW;
    if(array_key_exists("review", $_GET)){
        #We're in student mode
        $id = $_GET["review"];
        $reviewerID = $USERID;

        $reviewAssignments = $assignment->getAssignedReviews($reviewerID);

        #Try and extract who the author is - if we have an invalid index, return to main
        if(!isset($reviewAssignments[$id]))
            throw new Exception("No review assignment with id $id");

        #Get the match id, then everything else
        $matchID = $reviewAssignments[$id];

        $submission = $assignment->getSubmission($matchID);
        if($assignment->reviewExists($matchID)){
            $review = $assignment->getReview($matchID);
        }else{
            $review = $assignment->getReviewDraft($matchID);
        }
        $reviewerName = $dataMgr->getUserDisplayName($reviewerID);
        $getParams = "&reviewid=$id";
    }
    else
    {
        //We better be an instructor
        $authMgr->enforceInstructor();

        if(array_key_exists("matchid", $_GET))
        {
            //This is easy, just go load it up
            $matchID = new MatchID($_GET["matchid"]);
            $submission = $assignment->getSubmission($matchID);
            if($assignment->reviewExists($matchID)){
                $review = $assignment->getReview($matchID);
            }else{
                $review = $assignment->getReviewDraft($matchID);
            }
            $reviewerName = $dataMgr->getUserDisplayName($review->reviewerID);
            $getParams = "&matchid=$matchID";
        }
        else if(array_key_exists("reviewer", $_GET))
        {
            //Get the submission, and make a new review
            $reviewer = require_from_get("reviewer");
            $submissionID = new SubmissionID(require_from_get("submissionid"));
            $submission = $assignment->getSubmission($submissionID);
            $review = new Review($assignment);
            $review->reviewerID = $USERID;
            $review->submissionID = $submissionID;
            if($reviewer == "instructor")
                $reviewerName = "Instructor ".$dataMgr->getUserDisplayName($USERID);
            else if($reviewer == "anonymous")
                $reviewerName = "Anonymous (by ".$dataMgr->getUserDisplayName($USERID).")";
            else
                throw new Exception("Unknown reviewer type '$reviewer'");
            $getParams = "&reviewer=$reviewer&submissionid=$submissionID";
        }
        else
        {
            //No idea what this is
            throw new Exception("No valid options specified");
        }

        //We need to see if we're running into an issue where someone else has touched
        if(!array_key_exists("force", $_GET) && (!isset($matchID) || !$assignment->reviewExists($matchID)) && $dataMgr->isInstructor($review->reviewerID))
        {
            //Figure out if anyone else has touched this
            $touches = $assignment->getTouchesForSubmission($review->submissionID);

            //Figure out if we have someone else in this array
            $maxI = sizeof($touches);
            for($i = 0; $i < $maxI; $i++)
            {
                if($touches[$i]->userID == $review->reviewerID->id)
                {
                    unset($touches[$i]);
                }
            }

            if(sizeof($touches))
            {
                //We need to print the warning message
                $content .= "<h1>Submission Touched</h1>\n";

                $content .= "This submission has been touched by the following users:<br><br>\n";

                $i = 0;
                foreach($touches as $touch)
                {
                    $content .= $dataMgr->getUserDisplayName(new UserID($touch->userID))." on <span id='touchdate$i' ></span></br>\n";
                    $content .= set_element_to_date("touchdate$i", $touch->timestamp, "html", $assignment->dateFormat);
                    $i++;
                }

                $getArgs="force=1&";
                foreach($_GET as $arg=>$val) {
                    $getArgs.="$arg=$val&";
                }
                $content .= "<br><a href='?$getArgs'>Force Review Anyways</a>";

                render_page();
            }
        }

        //If we're an instructor, we need to touch this
        if($dataMgr->isInstructor($review->reviewerID))
        {
            $assignment->touchSubmission($review->submissionID, $review->reviewerID);
        }

        #We can just override the data on this assignment so that we can force a write
        $beforeReviewStart = false;
        $afterReviewStop   = false;
    }

    #Check to make sure submissions are valid
    if($beforeReviewStart)
    {
        $content .= 'This assignment has not been posted';
    }
    else if($afterReviewStop)
    {
        $content .= 'Reviews can no longer be submitted';
    }
    else if($assignment->deniedUser($review->reviewerID))
    {
        $content .= 'You have been excluded from this assignment';
    }
    else #There's no reason not to run up the submission interface now
    {
        #Show the submission question
        $content .= "<h1>Submission Question</h1>\n";
        $content .= $assignment->submissionQuestion;

        #Get the review that we are currently working on
        $content .= "<h1>Submission</h1>\n";
        $content .= $submission->getHTML();

        //Get the validate function
        $content .= "<script> $(document).ready(function(){ $('#saveButton').click(function() {\n";
        $content .= "var error = false;\n";
        #$content .= "var res = $('#review').find('input[type=\"submit\"]:focus').attr('id');\n";
        $content .= $review->getValidationCode();
        $content .= "return !error;\n";
        $content .= "}); }); </script>\n";

        //Make the form
        $content .= "<h1>$reviewerName's Review</h1>\n";
        $content .= "<form id='review' action='".get_redirect_url("peerreview/submitreview.php?assignmentid=$assignment->assignmentID$getParams$closeOnDone")."' method='post'>";
        $content .= $review->getFormHTML();
        $content .= "<br><br><input type='submit' name='saveAction' id='saveButton' value='Submit' /><input type='submit' name='saveAction' value='Save Draft' />\n";
        $content .= "</form>\n";
    }

    render_page();
}catch(Exception $e){
    render_exception_page($e);
}

?>
