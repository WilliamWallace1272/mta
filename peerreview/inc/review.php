<?php

class Review
{
    private $assignment;
    public $submissionID;
    public $reviewerID;
    public $answers = array();

    function __construct(PeerReviewAssignment $assignment)
    {
        $this->assignment = $assignment;
    }

    function getHTML($showHiddenQuestions=false)
    {
        #The first line contains the score that this review gave, we need to gobble it up
        $html = "<h2>Score for Submission: ".precisionFloat($this->getScore())."<h2>\n";

        #Now we can start loading up questions
        foreach($this->assignment->getReviewQuestions() as $question)
        {
            if($showHiddenQuestions || !$question->hidden)
            {
                if(array_key_exists($question->questionID->id, $this->answers)) {
                    $html .= $question->getHTML($this->answers[$question->questionID->id]);
                } else {
                    $html .= $question->getHTML(null);
                }
                $html .= "\n";
            }
        }
        return $html;
    }

    function getShortHTML()
    {
        $html = "";
        foreach($this->assignment->getReviewQuestions() as $question)
        {
            if(array_key_exists($question->questionID->id, $this->answers)) {
                $html .= $question->getShortHTML($this->answers[$question->questionID->id]);
            } else {
                $html .= $question->getShortHTML(null);
            }
            $html .= "\n";
        }
        return $html;
    }

    function getValidationCode()
    {
        $code = "";
        foreach($this->assignment->getReviewQuestions() as $question)
        {
            $code .= $question->getValidationCode();
        }
        return $code;
    }

    function getFormHTML()
    {
        global $SITEURL, $dataMgr;
        $html = "";

        //Now the actual editor stuff
        foreach($this->assignment->getReviewQuestions() as $question)
        {
            if(array_key_exists($question->questionID->id, $this->answers)) {
                $html .= $question->getFormHTML($this->answers[$question->questionID->id]);
            } else {
                $html .= $question->getFormHTML(null);
            }
            $html .= "\n";
        }

        return $html;
    }

    function loadFromPost($POST)
    {
        foreach($this->assignment->getReviewQuestions() as $question)
        {
            $this->answers[$question->questionID->id] = $question->loadAnswerFromPost($POST);
        }
    }

    function getScore()
    {
        $score = 0;
        foreach($this->assignment->getReviewQuestions() as $question)
        {
            if(array_key_exists($question->questionID->id, $this->answers)) {
                $score += $question->getScore($this->answers[$question->questionID->id]);
            } else {
                $score += $question->getScore(null);
            }
        }
        return $score;
    }
};

?>
