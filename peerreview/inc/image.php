<?php
require_once(dirname(__FILE__)."/submission.php");

class ImageSubmission extends Submission
{
    public $imgData = "";
    public $imgWidth = 0;
    public $imgHeight = 0;
    public $text = "";

    function _loadFromPost($POST)
    {
        global $_FILES;
        if(!array_key_exists("imgfile", $_FILES) || !array_key_exists("text", $POST) ){
            throw new Exception("Missing data in FILES or POST");
        }

        //Get some text
        $this->text = get_html_purifier()->purify($POST["text"]);

        //We actually need to check for the file
        if ($_FILES["imgfile"]["error"] > 0)
        {
            throw new Exception("File upload error: " . $_FILES["imgfile"]["error"]);;
        }

        //Try and get the image data
        $imgFileName = $_FILES["imgfile"]["tmp_name"];
        list($width, $height, $type) = getimagesize($imgFileName);

        ini_set('memory_limit', '32M');
        switch ($type)
        {
            case IMAGETYPE_GIF:
                $img = imagecreatefromgif($imgFileName);
                break;
            case IMAGETYPE_JPEG:
                $img = imagecreatefromjpeg($imgFileName);
                break;
            case IMAGETYPE_PNG:
                $img = imagecreatefrompng($imgFileName);
                break;
            default:
                throw new Exception('Unrecognized image type ' . $type);
        }

        //Save our width and height
        $this->imgWidth = $width;
        $this->imgHeight = $height;

        $destFile = $imgFileName;
        $ok = imagepng($img , $destFile);
        if ($ok && is_file($destFile) ) {
            $f = fopen($destFile, 'rb');
            $this->imgData = fread($f, filesize($destFile));
            fclose($f);
            // Remove the tempfile
            unlink($destFile);
        }else{
            throw new Exception("Error uploading image - is your file corrupt?");
        }
    }

    function _getHTML($showHidden)
    {
        $html = "";
        $data = base64_encode($this->imgData);
        //$data = '';
        $html .= "<div style='margin-left:auto;margin-right:auto;margin-bottom:20px;text-align:center'>";
        $html .= "<img  width='$this->imgWidth' height='$this->imgHeight' src='data:image/png;base64,$data'/>";
        $html .= "</div>";

        $html .= $this->text;
        return $html;
    }

    function _getValidationCode()
    {
        //only if we have topics do we need to ensure that one has been picked
        $code  = "$('#error_file').html('').parent().hide();\n";
        $code .= "if(!$('#imgFile').val()) {";
        $code .= "$('#error_file').html('You must select an image file');\n";
        $code .= "$('#error_file').parent().show();\n";
        $code .= "error = true;}\n";

        $code .= "$('#error_text').html('').parent().hide();\n";
        //TODO: Make this a setting in an essay
        $code .= "if(getWordCount('textEdit') > 150) {";
        $code .= "$('#error_text').html('Your text can not be longer than 100 words. (Note: Some editors add phantom characters to your document, try cleaning the text by copying it into a program like notepad then pasting it in if you feel you receive this message in error)');\n";
        $code .= "$('#error_text').parent().show();\n";
        $code .= "error = true;}";
        return $code;
    }

    function _getFormHTML()
    {
        $html = "";
        $html .= "Image File: <input type='file' name='imgfile' id='imgFile' accept='image/gif,image/jpeg,image/png' /><br><br>";
        $html .= "<div class=errorMsg><div class='errorField' id='error_file'></div></div><br>\n";
        $html .= "<textarea name='text' cols='60' rows='40' class='mceEditor' id='textEdit' accept-charset='utf-8'>\n";
        $html .= htmlentities($this->text, ENT_COMPAT|ENT_HTML401,'UTF-8');
        $html .= "</textarea><br>\n";
        $html .= "<div class=errorMsg><div class='errorField' id='error_text'></div></div><br>\n";

        return $html;
    }

    function getFormAttribs() {
        return "enctype='multipart/form-data' action='api/upload'";
    }

};

class ImageSubmissionSettings extends SubmissionSettings
{
    function getFormHTML()
    {
        /*
        $html  = "<table width='100%' align='left'>\n";
        $html .= "<tr><td>Topic Combo Box Options (One per line)<br>Leave blank if you don't wany to have a selection</td>\n";
        $html .= "<td><textarea id='essayTopicTextArea' name='essayTopicTextArea' cols='40' rows='10' wrap='off'>";
        foreach($this->topics as $topic)
            $html .= "$topic\n";
        $html .= "</textarea></td><tr>\n";
        $html .= "</table>\n";
        return $html;
         */
        return "";
    }

    function loadFromPost($POST)
    {
        /*
        //We need to figure out the topics
        if(!array_key_exists("essayTopicTextArea", $POST))
            throw new Exception("Failed to get the topic text from POST");
        $this->topics = array();
        foreach(explode("\n", str_replace("\r", "", $POST['essayTopicTextArea'])) as $topic)
        {
            $topic = trim($topic);
            if($topic)
            {
                $this->topics[] = $topic;
            }
        }
        */
    }
};

class ImagePDOPeerReviewSubmissionHelper extends PDOPeerReviewSubmissionHelper
{
    function loadAssignmentSubmissionSettings(PeerReviewAssignment $assignment)
    {
        $assignment->submissionSettings = new ImageSubmissionSettings();
        /*
        //We just need to grab the topics
        $sh = $this->db->prepare("SELECT name, link FROM peer_review_assignment_article_response_settings WHERE assignmentID = ? ORDER BY articleIndex;");
        $sh->execute(array($assignment->assignmentID));

        while($res = $sh->fetch())
        {
            $obj = new stdClass;
            $obj->name = $res->name;
            $obj->link = $res->link;
            $assignment->submissionSettings->articles[] = $obj;
        }
        */
    }

    function saveAssignmentSubmissionSettings(PeerReviewAssignment $assignment, $isNewAssignment)
    {
        /*
        //Delete any old topics, and just write in the new ones
        $sh = $this->prepareQuery("deleteAssignmentArticleResponseSubmissionSettingsQuery", "DELETE FROM peer_review_assignment_article_response_settings WHERE assignmentID = ?;");
        $sh->execute(array($assignment->assignmentID));

        $sh = $this->prepareQuery("insertAssignmentArticleResponseSubmissionSettingsQuery", "INSERT INTO peer_review_assignment_article_response_settings (assignmentID, articleIndex, name, link) VALUES (?, ?, ?, ?);");
        $i = 0;
        foreach($assignment->submissionSettings->articles as $article)
        {
            $sh->execute(array($assignment->assignmentID, $i, $article->name, $article->link));
            $i++;
       }
       */
    }

    function getAssignmentSubmission(PeerReviewAssignment $assignment, SubmissionID $submissionID)
    {
        $submisison = new ImageSubmission($assignment->submissionSettings);
        $sh = $this->prepareQuery("getImageSubmissionQuery", "SELECT imgWidth, imgHeight, imgData, text FROM peer_review_assignment_images WHERE submissionID = ?;");
        $sh->execute(array($submissionID));
        if(!$res = $sh->fetch())
            throw new Exception("Failed to get image '$submissionID'");
        $submisison->text = $res->text;
        $submisison->imgWidth = $res->imgWidth;
        $submisison->imgHeight = $res->imgHeight;
        $submisison->imgData = $res->imgData;
        return $submisison;
    }

    function saveAssignmentSubmission(PeerReviewAssignment $assignment, Submission $submission, $isNewSubmission)
    {
        if($isNewSubmission)
        {
            $sh = $this->prepareQuery("saveImageSubmissionInsertQuery", "INSERT INTO peer_review_assignment_images (submissionID, imgWidth, imgHeight, imgData, text) VALUES(?, ?, ?, ?, ?);");
            $sh->execute(array($submission->submissionID, $submission->imgWidth, $submission->imgHeight, $submission->imgData, $submission->text));
        }
        else
        {
            $sh = $this->prepareQuery("saveImageSubmissionUpdateQuery", "UPDATE peer_review_assignment_images SET imgWidth = ?, imgHeight = ?, imgData = ?, text = ? WHERE submissionID = ?;");
            $sh->execute(array($submission->imgWidth, $submission->imgHeight, $submission->imgData, $submission->text, $submission->submissionID));
        }
    }
}


