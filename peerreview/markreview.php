<?php
include("inc/common.php");
try
{
    $title .= " | Mark Review";
    $dataMgr->requireCourse();
    $authMgr->enforceInstructor();

    $assignment=get_peerreview_assignment();
    $matchID = new MatchID(require_from_get("matchid"));

    $review = $assignment->getReview($matchID);
    $submission = $assignment->getSubmission($review->submissionID);

    $content .= "<h1>".$dataMgr->getUserDisplayName($submission->authorID)."'s Submission</h1>\n";
    $content .= $submission->getHTML();

    $content .= "<h1>".$dataMgr->getUserDisplayName($review->reviewerID)."'s Review</h1>\n";
    $content .= $review->getHTML(true);

    $content .= "<form id='mark' action='".get_redirect_url("peerreview/submitmark.php?assignmentid=$assignment->assignmentID&type=review&matchid=$matchID")."' method='post'>\n";
    $content .= $assignment->getReviewMark($matchID)->getFormHTML();
    $content .= "<br><br><input type='submit' value='Submit' />\n";
    $content .= "</form>\n";

    render_page();
}catch(Exception $e){
    render_exception_page($e);
}
?>