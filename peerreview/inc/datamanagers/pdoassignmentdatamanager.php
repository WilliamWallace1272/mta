<?php
require_once("inc/assignmentdatamanager.php");
require_once("peerreview/inc/common.php");
require_once("peerreview/inc/peerreviewassignment.php");
require_once("peerreview/inc/mark.php");
require_once("peerreview/inc/essay.php");
require_once("peerreview/inc/articleresponse.php");
require_once("peerreview/inc/review.php");
require_once("peerreview/inc/spotcheck.php");
require_once("peerreview/inc/appeal.php");

abstract class PDOPeerReviewSubmissionHelper
{
    function __construct($db)
    {
        $this->db = $db;
    }
    abstract function loadAssignmentSubmissionSettings(PeerReviewAssignment $assignment);
    abstract function saveAssignmentSubmissionSettings(PeerReviewAssignment $assignment, $isNewAssignment);

    abstract function getAssignmentSubmission(PeerReviewAssignment $assignment, SubmissionID $submissionID);
    abstract function saveAssignmentSubmission(PeerReviewAssignment $assignment, Submission $submission, $isNewSubmission);
    //Because PHP doesn't do multiple inheritance, we have to define this method all over the place
    protected function prepareQuery($name, $query)
    {
        if(!isset($this->$name)) {
            $this->$name = $this->db->prepare($query);
        }
        return $this->$name;
    }
}


class EssayPDOPeerReviewSubmissionHelper extends PDOPeerReviewSubmissionHelper
{
    function saveAssignmentSubmissionSettings(PeerReviewAssignment $assignment, $isNewAssignment)
    {
        //Delete any old topics, and just write in the new ones
        $sh = $this->prepareQuery("deleteAssignmentEssaySubmissionSettingsQuery", "DELETE FROM peer_review_assignment_essay_settings WHERE assignmentID = ?;");
        $sh->execute(array($assignment->assignmentID));

        $sh = $this->prepareQuery("insertAssignmentEssaySubmissionSettingsQuery", "INSERT INTO peer_review_assignment_essay_settings (assignmentID, topicIndex, topic) VALUES (?, ?, ?);");
        $i = 0;
        foreach($assignment->submissionSettings->topics as $topic)
        {
            $sh->execute(array($assignment->assignmentID, $i, $topic));
            $i++;
        }
    }

    function loadAssignmentSubmissionSettings(PeerReviewAssignment $assignment)
    {
        //We just need to grab the topics
        $sh = $this->db->prepare("SELECT topic FROM peer_review_assignment_essay_settings WHERE assignmentID = ? ORDER BY topicIndex;");
        $sh->execute(array($assignment->assignmentID));

        $assignment->submissionSettings = new EssaySubmissionSettings();
        while($res = $sh->fetch())
        {
            $assignment->submissionSettings->topics[] = $res->topic;
        }
    }

    function getAssignmentSubmission(PeerReviewAssignment $assignment, SubmissionID $submissionID)
    {
        $essay = new EssaySubmission($assignment->submissionSettings);
        $sh = $this->prepareQuery("getEssaySubmissionQuery", "SELECT `text`, topicIndex FROM peer_review_assignment_essays WHERE submissionID = ?;");
        $sh->execute(array($submissionID));
        if(!$res = $sh->fetch())
            throw new Exception("Failed to get essay '$submissionID'");
        $essay->text = $res->text;
        $essay->topicIndex = $res->topicIndex;
        return $essay;
    }

    function saveAssignmentSubmission(PeerReviewAssignment $assignment, Submission $essay, $isNewSubmission)
    {
        if($isNewSubmission)
        {
            $sh = $this->prepareQuery("saveEssaySubmissionInsertQuery", "INSERT INTO peer_review_assignment_essays (submissionID, text, topicIndex) VALUES(?, ?, ?);");
            $sh->execute(array($essay->submissionID, $essay->text, $essay->topicIndex));
        }
        else
        {
            $sh = $this->prepareQuery("saveEssaySubmissionUpdateQuery", "UPDATE peer_review_assignment_essays SET text = ?, topicIndex = ? WHERE submissionID = ?;");
            $sh->execute(array($essay->text, $essay->topicIndex, $essay->submissionID));
        }
    }
}




class PDOPeerReviewAssignmentDataManager extends AssignmentDataManager
{
    private $db;
    private $submissionHelpers = array();

    function __construct($type, PDODataManager $dataMgr)
    {
        global $PEER_REVIEW_SUBMISSION_TYPES;
        parent::__construct($type, $dataMgr);

        $this->db = $dataMgr->getDatabase();

        $this->submissionExistsByMatchQuery = $this->db->prepare("SELECT submissionID FROM peer_review_assignment_matches WHERE matchID=?;");
        $this->submissionExistsByAuthorQuery = $this->db->prepare("SELECT submissionID FROM peer_review_assignment_submissions WHERE assignmentID=? && authorID=?;");
        $this->submissionExistsQuery = $this->db->prepare("SELECT submissionID FROM peer_review_assignment_submissions WHERE submissionID=?;");

        $this->reviewQuestionsCache = array();

        foreach($PEER_REVIEW_SUBMISSION_TYPES as $type => $_)
        {
            $helperType = $type."PDOPeerReviewSubmissionHelper";
            $this->submissionHelpers[$type] = new $helperType($this->db);
        }
    }

    function loadAssignment(AssignmentID $assignmentID)
    {
        global $PEER_REVIEW_SUBMISSION_TYPES;
        #Go and include the assignment page
        $sh = $this->prepareQuery("loadAssignmentQuery", "SELECT name, submissionQuestion, submissionType, UNIX_TIMESTAMP(submissionStartDate) as submissionStartDate, UNIX_TIMESTAMP(submissionStopDate) as submissionStopDate, UNIX_TIMESTAMP(reviewStartDate) as reviewStartDate, UNIX_TIMESTAMP(reviewStopDate) as reviewStopDate, UNIX_TIMESTAMP(markPostDate) as markPostDate, UNIX_TIMESTAMP(appealStopDate) as appealStopDate, maxSubmissionScore, maxReviewScore, showMarksForReviewsReceived, showOtherReviews, showMarksForOtherReviews, showMarksForReviewedSubmissions FROM peer_review_assignment JOIN assignments ON assignments.assignmentID = peer_review_assignment.assignmentID WHERE peer_review_assignment.assignmentID=?;");
        $sh->execute(array($assignmentID));
        if(!$res = $sh->fetch())
        {
            throw new Exception("Could not get assignment '$assignmentID'");
        }

        $assignment = new PeerReviewAssignment($assignmentID, $res->name, $this);

        //Start copying things accross
        $assignment->submissionQuestion = $res->submissionQuestion;
        $assignment->submissionType = $res->submissionType;

        $assignment->submissionStartDate = $res->submissionStartDate;
        $assignment->submissionStopDate  = $res->submissionStopDate;

        $assignment->reviewStartDate = $res->reviewStartDate;
        $assignment->reviewStopDate  = $res->reviewStopDate;

        $assignment->markPostDate = $res->markPostDate;
        $assignment->appealStopDate = $res->appealStopDate;

        $assignment->maxSubmissionScore = $res->maxSubmissionScore;
        $assignment->maxReviewScore = $res->maxReviewScore;

        $assignment->showMarksForReviewsReceived = $res->showMarksForReviewsReceived;
        $assignment->showOtherReviews            = $res->showOtherReviews;
        $assignment->showMarksForOtherReviews    = $res->showMarksForOtherReviews;
        $assignment->showMarksForReviewedSubmissions = $res->showMarksForReviewedSubmissions;

        //Now we need to get the settings for our type
        if(!array_key_exists($assignment->submissionType, $PEER_REVIEW_SUBMISSION_TYPES))
            throw new Exception("Unknown submission type '$assignment->submissionType'");

        $this->submissionHelpers[$assignment->submissionType]->loadAssignmentSubmissionSettings($assignment);

        return $assignment;
    }


    function deleteAssignment(PeerReviewAssignment $assignment)
    {
        //The magic of foreign key constraints....
    }

    function saveAssignment(Assignment $assignment, $newAssignment)
    {
        if($newAssignment)
        {
            $sh = $this->db->prepare("INSERT INTO peer_review_assignment (submissionQuestion, submissionType, submissionStartDate, submissionStopDate, reviewStartDate, reviewStopDate, markPostDate, appealStopDate, maxSubmissionScore, maxReviewScore, showMarksForReviewsReceived, showOtherReviews, showMarksForOtherReviews, assignmentID) VALUES (?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?);");
        }
        else
        {
            $sh = $this->db->prepare("UPDATE peer_review_assignment SET submissionQuestion=?, submissionType=?, submissionStartDate=FROM_UNIXTIME(?), submissionStopDate=FROM_UNIXTIME(?), reviewStartDate=FROM_UNIXTIME(?), reviewStopDate=FROM_UNIXTIME(?), markPostDate=FROM_UNIXTIME(?), appealStopDate=FROM_UNIXTIME(?), maxSubmissionScore=?, maxReviewScore=?, showMarksForReviewsReceived=?, showOtherReviews=?, showMarksForOtherReviews=?, showMarksForReviewedSubmissions=? WHERE assignmentID=?;");
        }
        $sh->execute(array(
            $assignment->submissionQuestion,
            $assignment->submissionType,
            $assignment->submissionStartDate,
            $assignment->submissionStopDate,
            $assignment->reviewStartDate,
            $assignment->reviewStopDate,
            $assignment->markPostDate,
            $assignment->appealStopDate,
            $assignment->maxSubmissionScore,
            $assignment->maxReviewScore,
            $assignment->showMarksForReviewsReceived,
            $assignment->showOtherReviews,
            $assignment->showMarksForOtherReviews,
            $assignment->showMarksForReviewedSubmissions,
            $assignment->assignmentID
        ));

        //Now we need to save the data for our type
        $this->submissionHelpers[$assignment->submissionType]->saveAssignmentSubmissionSettings($assignment, $newAssignment);
    }

    function saveDeniedUsers(PeerReviewAssignment $assignment, $users)
    {
        $this->db->beginTransaction();
        $sh = $this->db->prepare("DELETE FROM peer_review_assignment_denied WHERE assignmentID=?;");
        $sh->execute(array($assignment->assignmentID));

        $sh = $this->db->prepare("INSERT INTO peer_review_assignment_denied (assignmentID, userID) VALUES (?, ?);");
        foreach($users as $userid)
        {
            $sh->execute(array($assignment->assignmentID, $userid));
        }
        $this->db->commit();
    }

    function saveIndependentUsers(PeerReviewAssignment $assignment, $users)
    {
        $this->db->beginTransaction();
        $sh = $this->db->prepare("DELETE FROM peer_review_assignment_independent WHERE assignmentID=?;");
        $sh->execute(array($assignment->assignmentID));

        $sh = $this->db->prepare("INSERT INTO peer_review_assignment_independent (assignmentID, userID) VALUES (?, ?);");
        foreach($users as $userid)
        {
            $sh->execute(array($assignment->assignmentID, $userid));
        }
        $this->db->commit();
    }

    function getDeniedUsers(PeerReviewAssignment $assignment)
    {
        $sh = $this->prepareQuery("getDeniedUsersQuery", "SELECT userID from peer_review_assignment_denied WHERE assignmentID=?;");
        $sh->execute(array($assignment->assignmentID));

        $deniedUsers = array();
        while($res = $sh->fetch())
        {
            $deniedUsers[$res->userID] = new UserID($res->userID);
        }
        return $deniedUsers;
    }

    function getIndependentUsers(PeerReviewAssignment $assignment)
    {
        $sh = $this->prepareQuery("getIndependentUsersQuery", "SELECT userID from peer_review_assignment_independent WHERE assignmentID=?;");
        $sh->execute(array($assignment->assignmentID));

        $independentUsers = array();
        while($res = $sh->fetch())
        {
            $independentUsers[$res->userID] = new UserID($res->userID);
        }
        return $independentUsers;
    }

    function getReviewQuestions(PeerReviewAssignment $assignment)
    {
        if(isset($this->reviewQuestionsCache[$assignment->assignmentID->id]))
        {
            return $this->reviewQuestionsCache[$assignment->assignmentID->id];
        }
        else
        {
            $sh = $this->db->prepare("SELECT questionID FROM peer_review_assignment_questions WHERE assignmentID=? ORDER BY displayPriority DESC;");
            $sh->execute(array($assignment->assignmentID));

            $questions = array();
            while($res = $sh->fetch())
            {
                $questions[] = $this->getReviewQuestion($assignment, new QuestionID($res->questionID));
            }
            $this->reviewQuestionsCache[$assignment->assignmentID->id] = $questions;
            return $questions;
        }
    }

    function getReviewQuestion(PeerReviewAssignment $assignment, QuestionID $questionID)
    {
        $sh = $this->prepareQuery("getReviewQuestionQuery", "SELECT questionName, questionText, questionType, hidden, displayPriority FROM peer_review_assignment_questions WHERE questionID=?;");
        $sh->execute(array($questionID));
        if(!$res = $sh->fetch())
            throw new Exception("Failed to get question with id '$questionID'");
        $type = $res->questionType;
        //Try and instantiate the class
        if(!class_exists($type))
            throw new Exception("Unknown Question type '$type'");
        $question = new $type($questionID, $res->questionName, $res->questionText, $res->hidden, $res->displayPriority);

        switch($type)
        {
        case "TextAreaQuestion":
            //Get the minLength
            $sh = $this->prepareQuery("getTextAreaReviewQuestionQuery", "SELECT minLength FROM peer_review_assignment_text_options WHERE questionID=?;");
            $sh->execute(array($question->questionID));
            if($res = $sh->fetch())
                $question->minLength = $res->minLength;
            else
                $question->minLength = 0;
            break;
        case "RadioButtonQuestion":
            $sh = $this->prepareQuery("getRadioButtonReviewQuestionQuery", "SELECT label, score FROM peer_review_assignment_radio_options WHERE questionID=? ORDER BY `index`;");
            $sh->execute(array($question->questionID));
            $question->options = array();
            while($res = $sh->fetch())
            {
                $question->options[] = new RadioButtonOption($res->label, $res->score);
            }
            break;
        default:
            throw new Exception("Unhandled question type '$type'");
        }
        return $question;
    }

    function saveReviewQuestion(PeerReviewAssignment $assignment, ReviewQuestion $question)
    {
        $this->db->beginTransaction();
        //Do we need to insert it first?
        $added = false;
        if(is_null($question->questionID))
        {
            $sh = $this->db->prepare("INSERT INTO peer_review_assignment_questions (assignmentID, questionName, questionText, questionType, hidden, displayPriority) SELECT :assignmentID, :name, :text, :type, :hidden, COUNT(assignmentID) FROM peer_review_assignment_questions WHERE assignmentID=:assignmentID");
            $sh->execute(array("assignmentID"=>$assignment->assignmentID, "name"=>$question->name, "text"=>$question->question, "type"=>get_class($question), "hidden"=>$question->hidden));
            $question->questionID = new QuestionID($this->db->lastInsertID());
            $added = true;
        }
        else
        {
            $sh = $this->db->prepare("UPDATE peer_review_assignment_questions SET questionName=?, questionText=?, hidden=? WHERE questionID=?;");
            $sh->execute(array($question->name, $question->question, $question->hidden, $question->questionID));
        }
        //Now do the rest of the saving for each type
        switch(get_class($question))
        {
        case "TextAreaQuestion":
            //All we need to do is record the min number of words
            if($added) {
                $sh = $this->prepareQuery("insertTextAreaReviewQuestionQuery", "INSERT INTO peer_review_assignment_text_options (minLength, questionID) VALUES (?, ?);");
            } else {
                $sh = $this->prepareQuery("updateTextAreaReviewQuestionQuery", "UPDATE peer_review_assignment_text_options SET minLength =? WHERE questionID=?;");
            }
            $sh->execute(array($question->minLength, $question->questionID));
            break;
        case "RadioButtonQuestion":
            //Delete all the old options for this question
            $sh = $this->prepareQuery("deleteRadioButtonReviewQuestionQuery", "DELETE FROM peer_review_assignment_radio_options WHERE questionID=?;");
            $sh->execute(array($question->questionID));
            //Now insert all the options
            $sh = $this->prepareQuery("insertRadioButtonReviewQuestionQuery", "INSERT INTO peer_review_assignment_radio_options (questionID, `index`, label, score) VALUES (?, ?, ?, ?);");

            $i = 0;
            foreach($question->options as $option)
            {
                $sh->execute(array($question->questionID, $i, $option->label, $option->score));
                $i++;
            }
            break;
        default:
            throw new Exception("Uknown question type '".get_class($question)."'");
        }

        $this->db->commit();
    }

    function moveReviewQuestionUp(PeerReviewAssignment $assignment, QuestionID $id)
    {
        $this->db->beginTransaction();
        $question = $this->getReviewQuestion($assignment, $id);
        $sh = $this->db->prepare("SELECT questionID FROM peer_review_assignment_questions WHERE assignmentID = ? && displayPriority = ?;");
        $sh->execute(array($assignment->assignmentID, $question->displayPriority+1));
        if(!$res = $sh->fetch())
            return;
        $sh = $this->db->prepare("UPDATE peer_review_assignment_questions SET displayPriority = ? - displayPriority WHERE questionID IN (?, ?);");
        $sh->execute(array(2*$question->displayPriority+1, $id, $res->questionID));
        $this->db->commit();
    }

    function moveReviewQuestionDown(PeerReviewAssignment $assignment, QuestionID $id)
    {
        $this->db->beginTransaction();
        $question= $this->getReviewQuestion($assignment, $id);
        $sh = $this->db->prepare("SELECT questionID FROM peer_review_assignment_questions WHERE assignmentID = ? && displayPriority = ?;");
        $sh->execute(array($assignment->assignmentID, $question->displayPriority-1));
        if(!$res = $sh->fetch())
            return;
        $sh = $this->db->prepare("UPDATE peer_review_assignment_questions SET displayPriority = ? - displayPriority WHERE questionID IN (?, ?);");
        $sh->execute(array(2*$question->displayPriority-1, $id, $res->questionID));
        $this->db->commit();
    }

    function deleteReviewQuestion(PeerReviewAssignment $assignment, QuestionID $id)
    {
        $sh = $this->db->prepare("DELETE from peer_review_assignment_questions WHERE questionID = ?;");
        $sh->execute(array($id));
    }

    function getAuthorSubmissionMap(PeerReviewAssignment $assignment)
    {
        $sh = $this->db->prepare("SELECT authorID, submissionID FROM peer_review_assignment_submissions LEFT JOIN peer_review_assignment_denied ON peer_review_assignment_submissions.authorID = peer_review_assignment_denied.userID && peer_review_assignment_submissions.assignmentID = peer_review_assignment_denied.assignmentID WHERE peer_review_assignment_denied.userID IS NULL && peer_review_assignment_submissions.assignmentID = ?;");
        $sh->execute(array($assignment->assignmentID));

        $map = array();
        while($res = $sh->fetch())
        {
            $map[$res->authorID] = new SubmissionID($res->submissionID);
        }
        return $map;
    }

    function getReviewerAssignment(PeerReviewAssignment $assignment)
    {
        $sh = $this->db->prepare("SELECT peer_review_assignment_matches.submissionID, reviewerID FROM peer_review_assignment_matches JOIN peer_review_assignment_submissions ON peer_review_assignment_submissions.submissionID = peer_review_assignment_matches.submissionID WHERE peer_review_assignment_submissions.assignmentID = ? && instructorForced = 0;");
        $sh->execute(array($assignment->assignmentID));
        $reviewerAssignment = array();
        while($res = $sh->fetch())
        {
            if(!array_key_exists($res->submissionID, $reviewerAssignment))
            {
                $reviewerAssignment[$res->submissionID] = array();
            }
            $reviewerAssignment[$res->submissionID][] = new UserID($res->reviewerID);
        }
        return $reviewerAssignment;
    }

    function saveReviewerAssignment(PeerReviewAssignment $assignment, $reviewerAssignment)
    {
        $this->db->beginTransaction();
        //We need to create matches for everything in the list here
        $checkForMatch = $this->db->prepare("SELECT matchID FROM peer_review_assignment_matches WHERE submissionID=? && reviewerID = ?;");
        $insertMatch = $this->db->prepare("INSERT INTO peer_review_assignment_matches (submissionID, reviewerID) VALUES (?, ?);");

        //Make a dictionary for the clean query
        $cleanQueries = array();
        function prepareCleanQuery(&$map, $db, $numUsers)
        {
            if(!array_key_exists($numUsers, $map))
            {
                if($numUsers == 0)
                {
                    $map[$numUsers] = $db->prepare("DELETE FROM peer_review_assignment_matches WHERE submissionID = ? && instructorForced=0;");
                }
                else
                {
                    $paramStr = "";
                    for($i=0; $i < $numUsers-1;$i++) { $paramStr.="?,";}
                    $paramStr.="?";
                    $map[$numUsers] = $db->prepare("DELETE FROM peer_review_assignment_matches WHERE submissionID = ? && reviewerID NOT IN ($paramStr) && instructorForced=0;");
                }
            }
            return $map[$numUsers];
        }

        foreach($this->getAuthorSubmissionMap($assignment) as $authorID => $submissionID)
        {
            //See if this match exists
            if(array_key_exists($submissionID->id, $reviewerAssignment))
            {
                $reviewers = $reviewerAssignment[$submissionID->id];
                foreach($reviewers as $reviewerID)
                {
                    $checkForMatch->execute(array($submissionID, $reviewerID));
                    if(!$res = $checkForMatch->fetch())
                    {
                        //We need to insert this match
                        $insertMatch->execute(array($submissionID, $reviewerID));
                    }
                }
            }
            else
            {
                $reviewers = array();
            }
            //Clean up any extra reviews that are not insructor forced
            $sh = prepareCleanQuery($cleanQueries, $this->db, sizeof($reviewers));
            //We need to put the submission ID in this array
            array_unshift($reviewers, $submissionID);
            $sh->execute($reviewers);
        }

        $this->db->commit();
    }

    function getAssignedReviews(PeerReviewAssignment $assignment, UserID $reviewerID)
    {
        $sh = $this->db->prepare("SELECT matchID, peer_review_assignment_matches.submissionID FROM peer_review_assignment_matches JOIN peer_review_assignment_submissions ON peer_review_assignment_submissions.submissionID = peer_review_assignment_matches.submissionID WHERE assignmentID = ? && reviewerID = ? && instructorForced = 0 ORDER BY matchID;");
        $sh->execute(array($assignment->assignmentID, $reviewerID));
        $assigned = array();
        while($res = $sh->fetch())
        {
            $assigned[] = new MatchID($res->matchID);
        }
        return $assigned;
    }

    function deniedUser(PeerReviewAssignment $assignment, $userID)
    {
        $sh = $this->prepareQuery("deniedUserQuery", "SELECT userID FROM peer_review_assignment_denied WHERE assignmentID=? && userID=?;");
        $sh->execute(array($assignment->assignmentID, $userID));
        return $sh->fetch() != null;
    }

    function independentUser(PeerReviewAssignment $assignment, $userID)
    {
        $sh = $this->prepareQuery("independentUserQuery", "SELECT userID FROM peer_review_assignment_independent WHERE assignmentID=? && userID=?;");
        $sh->execute(array($assignment->assignmentID, $userID));
        return $sh->fetch() != null;
    }

    function submissionExists(PeerReviewAssignment $assignment, MechanicalTA_ID $id)
    {
        switch(get_class($id))
        {
        case "SubmissionID":
            $this->submissionExistsQuery->execute(array($id));
            return  $this->submissionExistsQuery->fetch() != NULL;
        case "UserID":
            $this->submissionExistsByAuthorQuery->execute(array($assignment->assignmentID, $id));
            return $this->submissionExistsByAuthorQuery->fetch() != NULL;
        case "MatchID":
            $this->submissionExistsByMatchQuery->execute(array($id));
            return $this->submissionExistsByMatchQuery->fetch() != NULL;
        default:
            throw new Exception("Can't lookup an submission from a '".get_class($id)."'");
        }
    }

    function getSubmissionID(PeerReviewAssignment $assignment, MechanicalTA_ID $id)
    {
        switch(get_class($id))
        {
        case "UserID":
            $this->submissionExistsByAuthorQuery->execute(array($assignment->assignmentID, $id));
            $res = $this->submissionExistsByAuthorQuery->fetch();
            break;
        case "MatchID":
            $this->submissionExistsByMatchQuery->execute(array($id));
            $res = $this->submissionExistsByMatchQuery->fetch();
            break;
        default:
            throw new Exception("Can't lookup an submission from a '".get_class($id)."'");
        }
        if($res == NULL)
            throw new Exception("Could not find an submission by author '$id'");
        return new SubmissionID($res->submissionID);
    }

    function reviewAnswersExist(PeerReviewAssignment $assignment, MatchID $matchID)
    {
        $sh = $this->prepareQuery("reviewAnswersExistQuery", "SELECT matchID FROM peer_review_assignment_review_answers WHERE matchID = ? LIMIT 1;");
        $sh->execute(array($matchID));
        return $sh->fetch() != NULL;
    }

    function getSubmissionMark(PeerReviewAssignment $assignment, SubmissionID $submissionID)
    {
        $sh = $this->prepareQuery("getSubmissionMarkQuery", "SELECT score, comments, automatic FROM peer_review_assignment_submission_marks WHERE submissionID=?;");
        $sh->execute(array($submissionID));
        if($res = $sh->fetch())
        {
            return new Mark($res->score, $res->comments, $res->automatic);
        }
        return new Mark();
    }

    function getReviewMark(PeerReviewAssignment $assignment, MatchID $matchID)
    {
        $sh = $this->prepareQuery("getReviewMarkQuery", "SELECT score, comments, automatic FROM peer_review_assignment_review_marks WHERE matchID=?;");
        $sh->execute(array($matchID));
        if($res = $sh->fetch())
        {
            return new Mark($res->score, $res->comments, $res->automatic);
        }
        return new Mark();
    }

    function saveSubmissionMark(PeerReviewAssignment $assignment, Mark $mark, SubmissionID $submissionID)
    {
        $sh = $this->prepareQuery("saveSubmissionMarkQuery", "INSERT INTO peer_review_assignment_submission_marks (submissionID, score, comments) VALUES (:submissionID, :score, :comments) ON DUPLICATE KEY UPDATE score=:score, comments=:comments;");
        $sh->execute(array(":submissionID" => $submissionID, "score"=>$mark->score, "comments"=>$mark->comments));
    }

    function saveReviewMark(PeerReviewAssignment $assignment, Mark $mark, MatchID $matchID)
    {
        $sh = $this->prepareQuery("saveReviewMarkQuery", "INSERT INTO peer_review_assignment_review_marks (matchID, score, comments) VALUES (:matchID, :score, :comments) ON DUPLICATE KEY UPDATE score=:score, comments=:comments;");
        $sh->execute(array(":matchID" => $matchID, "score"=>$mark->score, "comments"=>$mark->comments));
    }

    function deleteSubmission(PeerReviewAssignment $assignment, SubmissionID $submissionID)
    {
        $sh = $this->db->prepare("DELETE FROM peer_review_assignment_submissions WHERE submissionID = ?;");
        $sh->execute(array($submissionID));
        $sh = $this->db->prepare("DELETE FROM peer_review_assignment_submission_marks WHERE submissionID = ?;");
        $sh->execute(array($submissionID));
    }

    function getSubmission(PeerReviewAssignment $assignment, $id)
    {
        switch(get_class($id))
        {
        case "SubmissionID":
            //They want the submission with this id
            $sh = $this->prepareQuery("getSubmissionQuery","SELECT submissionID, authorID, noHallOfFame FROM peer_review_assignment_submissions WHERE submissionID=?;");
            $sh->execute(array($id));
            $res = $sh->fetch();
            break;
        case "UserID":
            //They want to get the submission by the author
            $sh = $this->prepareQuery("getSubmissionByAuthorQuery", "SELECT submissionID, authorID, noHallOfFame FROM peer_review_assignment_submissions WHERE assignmentID=? && authorID=?;");
            $sh->execute(array($assignment->assignmentID, $id));
            $res = $sh->fetch();
            break;
        case "MatchID":
            //They want to get the submission for the given review
            $sh = $this->prepareQuery("getSubmissionByMatchQuery", "SELECT peer_review_assignment_submissions.submissionID, authorID, noHallOfFame FROM peer_review_assignment_submissions JOIN peer_review_assignment_matches ON peer_review_assignment_matches.submissionID = peer_review_assignment_submissions.submissionID WHERE matchID=?;");
            $sh->execute(array($id));
            $res = $sh->fetch();
            break;
        default:
            throw new Exception("Can't lookup an submission from a '".get_class($id)."'");
        }
        if(!$res)
            throw new Exception("Could not get submission id by ".get_class($id)." '$id'");

        $submission = $this->submissionHelpers[$assignment->submissionType]->getAssignmentSubmission($assignment, new SubmissionID($res->submissionID));
        $submission->authorID = new UserID($res->authorID);
        $submission->noHallOfFame = $res->noHallOfFame;
        return $submission;
    }

    function saveSubmission(PeerReviewAssignment $assignment, Submission $submission)
    {
        $isNewSubmission = !isset($submission->submissionID) || is_null($submission->submissionID);
        if($isNewSubmission)
        {
            $sh = $this->db->prepare("INSERT INTO peer_review_assignment_submissions (assignmentID, authorID, noHallOfFame) VALUES(?, ?, ?);");
            $sh->execute(array($assignment->assignmentID, $submission->authorID, $submission->noHallOfFame));
            $submission->submissionID = new SubmissionID($this->db->lastInsertID());
        }
        else
        {
            $sh = $this->db->prepare("UPDATE peer_review_assignment_submissions SET noHallOfFame=? WHERE submissionID=?;");
            $sh->execute(array($submission->text, $submission->submissionID));
        }
        $this->submissionHelpers[$assignment->submissionType]->saveAssignmentSubmission($assignment, $submission, $isNewSubmission);
    }


    function getUserIDForInstructorReview(PeerReviewAssignment $assignment, UserID $baseID, $username, SubmissionID $submissionID)
    {
        //Try and find an unused shadow id
        $sh = $this->db->prepare("SELECT userID from users WHERE NOT EXISTS (SELECT * from peer_review_assignment_matches WHERE userID = reviewerID && submissionID = ?) && ((userType = 'shadowinstructor' && substr(username, 1, ?) = ?) || userID = ?) ORDER BY userID LIMIT 1;");
        $basename = $username."__shadow";
        $sh->execute(array(
            $submissionID,
            strlen($basename),
            $basename,
            $baseID
        ));

        if($res = $sh->fetch())
        {
            //Return the id
            return $res->userID;
        }
        else
        {
            //We have to go and create a new shadow user
            $sh = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE userType = 'shadowinstructor' && substr(username, 1, ?) = ?;");
            $sh->execute(array(strlen($basename), $basename));
            $nameInfo = $this->dataMgr->getUserFirstAndLastNames($baseID);
            $count = $sh->fetch()->count;
            return $this->dataMgr->addUser($basename.$count, $nameInfo->firstName, $nameInfo->lastName." (".($count+1).")", 0, 'shadowinstructor');
        }
    }

    function getUserIDForAnonymousReview(PeerReviewAssignment $assignment, UserID $baseID, $username, SubmissionID $submissionID)
    {
        //Try and find an unused anonymous id
        $sh = $this->db->prepare("SELECT userID from users WHERE NOT EXISTS (SELECT * from peer_review_assignment_matches WHERE userID = reviewerID && submissionID = ?) && (userType = 'anonymous' && substr(username, 1, ?) = ?) ORDER BY userID LIMIT 1;");
        $basename = $username."__anonymous";
        $sh->execute(array(
            $submissionID,
            strlen($basename),
            $basename
        ));

        if($res = $sh->fetch())
        {
            //Return the id
            return $res->userID;
        }
        else
        {
            //We have to go and create a new shadow user
            $sh = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE userType = 'anonymous' && substr(username, 1, ?) = ?;");
            $sh->execute(array(strlen($basename), $basename));
            $nameInfo = $this->dataMgr->getUserFirstAndLastNames($baseID);
            $count = $sh->fetch()->count;
            $lastName = $nameInfo->lastName;
            if($count > 0)
                $lastName.= " (".($count+1).")";
            return $this->dataMgr->addUser($basename.$count, "Anonymous ".$nameInfo->firstName, $lastName, 0, 'anonymous');
        }
    }

    function createMatch(PeerReviewAssignment $assignment, SubmissionID $submissionID, UserID $reviewerID, $instructorForced=false)
    {
        $sh = $this->db->prepare("INSERT INTO peer_review_assignment_matches (submissionID, reviewerID, instructorForced) VALUES (?, ?, ?);");
        $sh->execute(array($submissionID, $reviewerID, $instructorForced));
        return new MatchID($this->db->lastInsertID());
    }

    function getReview(PeerReviewAssignment $assignment, MechanicalTA_ID $id, UserID $reviewer = null)
    {
        if(!is_null($reviewer))
        {
            //They are looking up the review on a author-reviewer pair
            if(get_class($id) != "UserID")
                throw new Exception("This call wanted a user id as the second arg, but got ".get_class($id));
            //Do the query
            $sh = $this->prepareQuery("getReviewHeaderByAuthorReviewerPairQuery", "SELECT matchID, peer_review_assignment_matches.submissionID, reviewerID FROM peer_review_assignment_matches JOIN peer_review_assignment_submissions ON peer_review_assignment_submissions.submissionID = peer_review_assignment_matches.submissionID WHERE assignmentID = ? && peer_review_assignment_submissions.authorID=?, reviewerID = ?;");
            $sh->execute(array($assignment->assignmentID, $id, $reviewer));
            $headerRes = $sh->fetch();
        }
        else if(get_class($id) == "MatchID")
        {
            //We better have a match id
            $sh = $this->prepareQuery("getReviewHeaderByMatchQuery", "SELECT matchID, peer_review_assignment_matches.submissionID, reviewerID FROM peer_review_assignment_matches JOIN peer_review_assignment_submissions ON peer_review_assignment_submissions.submissionID = peer_review_assignment_matches.submissionID WHERE matchID=?;");
            $sh->execute(array($id));
            $headerRes = $sh->fetch();
        }
        else
        {
            throw new Exception("Unable to get a review using a ".get_class($id));
        }
        if(!$headerRes)
            throw new Exception("Could not find review");

        $questionSH = $this->prepareQuery("getReviewByMatchQuery", "SELECT questionID, answerInt, answerText FROM peer_review_assignment_review_answers WHERE matchID=?;");
        $questionSH->execute(array($headerRes->matchID));

        //Make a new review
        $review = new Review($assignment);
        $review->matchID = new MatchID($headerRes->matchID);
        $review->submissionID = new SubmissionID($headerRes->submissionID);
        $review->reviewerID = new UserID($headerRes->reviewerID);
        while($res = $questionSH->fetch())
        {
            $answer = new ReviewAnswer();
            if(!is_null($res->answerText))
                $answer->text = $res->answerText;
            if(!is_null($res->answerInt))
                $answer->int = $res->answerInt;

            $review->answers[$res->questionID] = $answer;
        }
        return $review;
    }

    function saveReview(PeerReviewAssignment $assignment, Review $review)
    {
        $this->db->beginTransaction();
        $this->deleteReview($assignment, $review->matchID, false);
        $sh = $this->prepareQuery("insertReviewAnswerQuery", "INSERT INTO peer_review_assignment_review_answers (matchID, questionID, answerInt, answerText) VALUES (?, ?, ?, ?);");
        foreach($review->answers as $questionID => $answer)
        {
            $answerText = NULL;
            $answerInt = NULL;
            if(isset($answer->text) && !is_null($answer->text))
                $answerText = $answer->text;
            if(isset($answer->int) && !is_null($answer->int))
                $answerInt = $answer->int;
            $sh->execute(array($review->matchID, $questionID, $answerInt, $answerText));
        }
        $this->db->commit();
    }

    function deleteReview(PeerReviewAssignment $assignment, MatchID $id, $removeMatch=true, $onlyIfForced=true)
    {
        $sh = $this->db->prepare("DELETE FROM peer_review_assignment_review_answers WHERE matchID = ?;");
        $sh->execute(array($id));
        $sh = $this->db->prepare("DELETE FROM peer_review_assignment_review_marks WHERE matchID = ?;");
        $sh->execute(array($id));
        if($removeMatch)
        {
            if($onlyIfForced) {
                $sh = $this->db->prepare("DELETE FROM peer_review_assignment_matches WHERE matchID = ? && instructorForced = 1;");
            } else {
                $sh = $this->db->prepare("DELETE FROM peer_review_assignment_matches WHERE matchID = ?;");
            }
            $sh->execute(array($id));
        }
    }

    function getMatchesForSubmission(PeerReviewAssignment $assignment, SubmissionID $submissionID)
    {
        $sh = $this->prepareQuery("getMatchesForSubmissionQuery", "SELECT matchID FROM peer_review_assignment_matches JOIN users ON reviewerID = userID WHERE submissionID = ? ORDER BY userType, matchID;");
        $sh->execute(array($submissionID));

        $matches = array();
        while($res = $sh->fetch())
        {
            $matches[] = new MatchID($res->matchID);
        }
        return $matches;
    }

    function getReviewsForSubmission(PeerReviewAssignment $assignment, SubmissionID $submissionID)
    {
        $matches = $this->getMatchesForSubmission($assignment, $submissionID);

        $reviews = array();
        foreach($matches as $matchID) {
            $reviews[] = $this->getReview($assignment, $matchID);
        }
        return $reviews;
    }

    function saveSpotCheck(PeerReviewAssignment $assignment, SpotCheck $check)
    {
        $sh = $this->prepareQuery("saveSpotCheckQuery", "INSERT INTO peer_review_assignment_spot_checks (submissionID, checkerID, status) VALUES (:submissionID, :checkerID, :status) ON DUPLICATE KEY UPDATE checkerID=:checkerID, status=:status;");
        $sh->execute(array("submissionID"=>$check->submissionID, "checkerID"=>$check->checkerID, "status"=>$check->status));
    }

    function getSpotCheck(PeerReviewAssignment $assignment, SubmissionID $submissionID)
    {
        $sh = $this->prepareQuery("getSpotCheckQuery", "SELECT submissionID, checkerID, status FROM peer_review_assignment_spot_checks checks WHERE submissionID = ?;");
        $sh->execute(array($submissionID));
        $res = $sh->fetch();
        if(!$res)
            throw new Exception("No spot check for submission $submissionID");
        return new SpotCheck(new SubmissionID($res->submissionID), new UserID($res->checkerID), $res->status);
    }

    function getSpotCheckMap(PeerReviewAssignment $assignment)
    {
        $sh = $this->prepareQuery("getSpotCheckMapQuery", "SELECT checks.submissionID, checkerID, status FROM peer_review_assignment_spot_checks checks JOIN peer_review_assignment_submissions subs ON checks.submissionID = subs.submissionID WHERE assignmentID = ?;");
        $sh->execute(array($assignment->assignmentID));

        $checks = array();
        while($res = $sh->fetch())
        {
            $checks[$res->submissionID] = new SpotCheck(new SubmissionID($res->submissionID), new UserID($res->checkerID), $res->status);
        }
        return $checks;
    }

    function getAssignmentStatistics($assignment)
    {
        $stats = new stdClass;

        $sh = $this->prepareQuery("numSubmissionsQuery", "SELECT count(submissionID) as c FROM peer_review_assignment_submissions WHERE assignmentID=?;");
        $sh->execute(array($assignment->assignmentID));
        $stats->numSubmissions = $sh->fetch()->c;

        $sh = $this->prepareQuery("numPossibleSubmissionsQuery", "SELECT count(users.userID) as c from users LEFT JOIN peer_review_assignment_denied denied ON users.userID = denied.userID WHERE (assignmentID is null || assignmentID != ?) && userType = 'student';");
        $sh->execute(array($assignment->assignmentID));
        $stats->numPossibleSubmissions = $sh->fetch()->c;
        $sh = $this->prepareQuery("numStudentReviewsQuery","SELECT count(distinct matches.matchID) as c FROM peer_review_assignment_matches matches JOIN peer_review_assignment_submissions subs ON matches.submissionID = subs.submissionID JOIN peer_review_assignment_review_answers ans ON matches.matchID = ans.matchID WHERE assignmentID=? && instructorForced = 0;");

        $sh->execute(array($assignment->assignmentID));
        $stats->numStudentReviews = $sh->fetch()->c;

        $sh = $this->prepareQuery("numPossibleStudentReviewsQuery", "SELECT COUNT(*) as c FROM peer_review_assignment_matches matches JOIN peer_review_assignment_submissions subs ON matches.submissionID = subs.submissionID WHERE assignmentID=? && instructorForced = 0;");
        $sh->execute(array($assignment->assignmentID));
        $stats->numPossibleStudentReviews = $sh->fetch()->c;

        $sh = $this->prepareQuery("numUnmarkedSubmissionsQuery","SELECT COUNT(*) as c FROM peer_review_assignment_submissions subs JOIN peer_review_assignment_submission_marks marks ON subs.submissionID = marks.submissionID WHERE assignmentID=?;");
        $sh->execute(array($assignment->assignmentID));
        $stats->numUnmarkedSubmissions = $stats->numSubmissions - $sh->fetch()->c;

        $sh = $this->prepareQuery("numUnmarkedReviewsQuery","SELECT count(distinct matches.matchID) as c FROM peer_review_assignment_matches matches JOIN peer_review_assignment_submissions subs ON matches.submissionID = subs.submissionID JOIN peer_review_assignment_review_answers ans ON matches.matchID = ans.matchID JOIN peer_review_assignment_review_marks marks ON marks.matchID = matches.matchID WHERE assignmentID=? && instructorForced = 0;");
        $sh->execute(array($assignment->assignmentID));
        $stats->numUnmarkedReviews = $stats->numStudentReviews - $sh->fetch()->c;

        $sh = $this->prepareQuery("numPendingAppealsQuery","SELECT COUNT(matches.matchID) as c FROM peer_review_assignment_appeal_messages messages LEFT JOIN peer_review_assignment_appeal_messages messages2 ON (messages.appealMessageID < messages2.appealMessageID) JOIN peer_review_assignment_matches matches ON matches.matchID = messages.matchID JOIN peer_review_assignment_submissions submissions ON submissions.submissionID = matches.submissionID JOIN users ON messages.authorID = users.userID WHERE messages2.appealMessageID IS NULL && submissions.assignmentID = ? && users.userType='student';");
        $sh->execute(array($assignment->assignmentID));
        $stats->numPendingAppeals = $sh->fetch()->c;

        $sh = $this->prepareQuery("numPendingSpotChecksQuery","SELECT COUNT(*) as c FROM peer_review_assignment_spot_checks checks JOIN peer_review_assignment_submissions subs ON subs.submissionID = checks.submissionID WHERE status = 'pending' && assignmentID = ?;");
        $sh->execute(array($assignment->assignmentID));
        $stats->numPendingSpotChecks = $sh->fetch()->c;

        return $stats;
    }

    function getReviewMap(PeerReviewAssignment $assignment)
    {
        //First, figure out what should be there
        $reviewMap = array();

        //This is a beast, all it does is grab a list of submission-reviewer ids for the current assignment, that actually have something in the answers array, ordering by user type then match id
        //It also indicates if a match has answers (questionID != NULL)
        $sh = $this->prepareQuery("getExistingReviewerMapQuery", "SELECT peer_review_assignment_matches.submissionID, peer_review_assignment_matches.reviewerID, peer_review_assignment_review_answers.questionID, peer_review_assignment_matches.matchID, peer_review_assignment_matches.instructorForced FROM peer_review_assignment_matches JOIN peer_review_assignment_submissions ON peer_review_assignment_matches.submissionID = peer_review_assignment_submissions.submissionID LEFT JOIN peer_review_assignment_review_answers ON peer_review_assignment_matches.matchID = peer_review_assignment_review_answers.matchID JOIN users ON peer_review_assignment_matches.reviewerID = users.userID WHERE peer_review_assignment_submissions.assignmentID = ? GROUP BY peer_review_assignment_matches.matchID ORDER BY users.userType, peer_review_assignment_matches.matchID;");
        $sh->execute(array($assignment->assignmentID));
        while($res = $sh->fetch())
        {
            if(!array_key_exists($res->submissionID, $reviewMap))
            {
                $reviewMap[$res->submissionID] = array();
            }
            $obj = new stdClass();
            $obj->reviewerID = new UserID($res->reviewerID);
            $obj->exists = !is_null($res->questionID);
            $obj->matchID = new MatchID($res->matchID);
            $obj->instructorForced = $res->instructorForced;
            $reviewMap[$res->submissionID][] = $obj;
        }
        return $reviewMap;
    }

    function getMatchScoreMap(PeerReviewAssignment $assignment)
    {
        $scoreMap = array();

        //Another beast. This avoids us from having to load all the reviews, and computes this all on the DB
        $sh = $this->prepareQuery("getMatchScoreMapQuery", "SELECT peer_review_assignment_review_answers.matchID, SUM(score) as score FROM peer_review_assignment_review_answers JOIN peer_review_assignment_matches ON peer_review_assignment_matches.matchID = peer_review_assignment_review_answers.matchID JOIN peer_review_assignment_submissions ON peer_review_assignment_submissions.submissionID = peer_review_assignment_matches.submissionID LEFT JOIN peer_review_assignment_radio_options ON peer_review_assignment_radio_options.questionID = peer_review_assignment_review_answers.questionID WHERE peer_review_assignment_radio_options.`index` = peer_review_assignment_review_answers.answerInt && peer_review_assignment_submissions.assignmentID = ? GROUP BY peer_review_assignment_review_answers.matchID;");
        $sh->execute(array($assignment->assignmentID));

        while($res = $sh->fetch())
        {
            $scoreMap[$res->matchID] = $res->score;
        }

        return $scoreMap;
    }

    function appealExists(PeerReviewAssignment $assignment, MatchID $matchID)
    {
        $sh = $this->prepareQuery("appealExistsQuery", "SELECT COUNT(appealMessageID) as c FROM peer_review_assignment_appeal_messages WHERE matchID = ?;");
        $sh->execute(array($matchID));
        return $sh->fetch()->c > 0;
    }

    function getAppeal(PeerReviewAssignment $assignment, MatchID $matchID)
    {
        $sh = $this->prepareQuery("getAppealQuery", "SELECT appealMessageID, authorID, text FROM peer_review_assignment_appeal_messages WHERE matchID = ? ORDER BY appealMessageID;");
        $sh->execute(array($matchID));

        $appeal = new Appeal($matchID);
        while($res = $sh->fetch())
        {
            $appeal->messages[] = new AppealMessage($res->appealMessageID, $matchID, new UserID($res->authorID), $res->text);
        }

        return $appeal;
    }

    function saveAppealMessage(PeerReviewAssignment $assignment, AppealMessage $message)
    {
        if(!isset($message->appealMessageID) || is_null($message->appealMessageID))
        {
            $sh = $this->db->prepare("INSERT INTO peer_review_assignment_appeal_messages (matchID, authorID, viewedByStudent, text) VALUES(?, ?, 0, ?);");
            $sh->execute(array($message->matchID, $message->authorID, $message->message));
            $message->appealMessageID = $this->db->lastInsertID();
        }
        else
        {
            $sh = $this->db->prepare("UPDATE peer_review_assignment_appeal_messages SET text = ? WHERE appealMessageID = ?;");
            $sh->execute(array($message->message, $message->appealMessageID));
        }
    }

    function markAppealAsViewedByStudent(PeerReviewAssignment $assignment, MatchID $matchID)
    {
        $sh = $this->db->prepare("UPDATE peer_review_assignment_appeal_messages SET viewedByStudent = 1 WHERE matchID = ?;");
        $sh->execute(array($matchID));
    }

    function hasNewAppealMessage(PeerReviewAssignment $assignment, MatchID $matchID)
    {
        $sh = $this->prepareQuery("hasNewAppealMessageQuery", "SELECT COUNT(appealMessageID) as c FROM peer_review_assignment_appeal_messages WHERE matchID = ? && viewedByStudent=0;");
        $sh->execute(array($matchID));
        return $sh->fetch()->c > 0;
    }

    function getReviewAppealMap(PeerReviewAssignment $assignment)
    {
        $sh = $this->prepareQuery("getReviewAppealMapQuery", "SELECT matches.matchID, users.userType='student' as needsResponse FROM peer_review_assignment_appeal_messages messages LEFT JOIN peer_review_assignment_appeal_messages messages2 ON (messages.appealMessageID < messages2.appealMessageID) JOIN peer_review_assignment_matches matches ON matches.matchID = messages.matchID JOIN peer_review_assignment_submissions submissions ON submissions.submissionID = matches.submissionID JOIN users ON messages.authorID = users.userID WHERE messages2.appealMessageID IS NULL && submissions.assignmentID = ?;");
        $sh->execute(array($assignment->assignmentID));

        $map = array();
        while($res = $sh->fetch())
        {
            $map[$res->matchID] = $res->needsResponse;
        }
        return $map;
    }

    //Because PHP doesn't do multiple inheritance, we have to define this method all over the place
    private function prepareQuery($name, $query)
    {
        if(!isset($this->$name)) {
            $this->$name = $this->db->prepare($query);
        }
        return $this->$name;
    }
};
?>

