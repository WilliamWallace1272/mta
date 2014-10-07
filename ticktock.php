<?php
require_once("inc/common.php");
require_once("peerreview/inc/calibrationutils.php");
try
{
    //Has the course been set?
    if(!$dataMgr->courseName)
    {
        //Nope, run up the course picker for people
        $content .= "<h1>Course Select</h1>";
        foreach($dataMgr->getCourses() as $courseObj)
        {
            if($courseObj->browsable)
                $content .= "<a href='$SITEURL$courseObj->name/'>$courseObj->displayName</a><br>";
        }
        render_page();
    }
    else
    {
        $authMgr->enforceLoggedIn();

        #$dataMgr->numStudents();
        $content .= show_timezone();

        #Figure out what courses are availible, and display them to the user (showing what roles they have)
        $assignments = $dataMgr->getAssignments();
		
		#TO-DO Section and Calibration Section processing
		if($dataMgr->isStudent($USERID))
		{		
			$currentAverage = getWeightedAverage($USERID);
			$demotion = $dataMgr->getDemotionEntry($USERID);
			
			$items = array();
			
			$latestCalibrationAssignment = latestCalibrationAssignment();
			
			foreach($assignments as $assignment)
			{			
				if(!$assignment->showForUser($USERID))
                continue;
       			
				if($assignment->submissionStartDate <= $NOW AND $assignment->submissionStopDate > $NOW)
				{
					if(!($assignment->password == NULL) AND !($dataMgr->hasEnteredPassword($assignment->assignmentID, $USERID)))
					{		
						$item = new stdClass();
						$item->type = "Password";
						$item->assignmentID = $assignment->assignmentID;
						$item->endDate = $assignment->submissionStopDate;
						$item->html = 
						"<table width='100%'><tr><td class='column1'><h4>$assignment->name</h4></td>
						<td class='column2'>Password</td></td>
						<td class='column3'><form action='enterpassword.php?assignmentid=".$assignment->assignmentID."' method='post'><table width='100%'><td>Enter password:<input type='text' name='password' size='10'/></td>
						<td><input type='submit' value='Enter'/></td></table></form></td>
						<td class='column4'>".phpDate($assignment->submissionStopDate)."</td></tr></table>\n";
						insert($item, $items);
					}
					else 
					{
						if(!$assignment->submissionExists($USERID))
						{
							$item = new stdClass();
							$item->type = "Submission";
							$item->assignmentID = $assignment->assignmentID;
							$item->endDate = $assignment->submissionStopDate;
							$item->html =
							"<table width='100%' class='tables'><tr><td class='column1'><h4>$assignment->name</h4></td>
							<td class='column2'>".ucfirst($assignment->submissionType)."</td>
							
							<td class='column3'><form action='".get_redirect_url("peerreview/editsubmission.php?assignmentid=$assignment->assignmentID")."' method='post'><input type='submit' value='Create Submission'/></form></td>
							<td class='column4'>".phpDate($assignment->submissionStopDate)."</td></tr></table>\n";
							insert($item, $items);
						}
					}	
				}
				
				if($assignment->password == NULL || $dataMgr->hasEnteredPassword($assignment->assignmentID, $USERID))
				{
					if($assignment->reviewStartDate <= $NOW AND $assignment->reviewStopDate > $NOW)
					{
						$reviewAssignments = $assignment->getAssignedReviews($USERID);
						$id=0;
						foreach($reviewAssignments as $matchID)
						{
							$temp = $id+1;
							if(!$assignment->reviewExists($matchID))
							{
								$item = new stdClass();
								$item->type = "Peer Review";
								$item->assignmentID = $assignment->assignmentID;
								$item->endDate = $assignment->reviewStopDate;
								$item->html = 
								"<table width='100%'><tr><td class='column1'><h4>$assignment->name</h4></td>
								<td class='column2'>Peer Review $temp</td>
								
								<td class='column3'><a href='".get_redirect_url("peerreview/editreview.php?assignmentid=$assignment->assignmentID&review=$id")."''><button>Go</button></a></td>
								<td class='column4'>".phpDate($assignment->reviewStopDate)."</td></tr></table>\n";
								insert($item, $items);
							}
							$id++;			
						} 
					}
					
					if($assignment->calibrationStartDate <= $NOW AND $assignment->reviewStartDate > $NOW)
					{
						$calibrationReviewAssignments = $assignment->getAssignedCalibrationReviews($USERID);
						$id=0;
						foreach($calibrationReviewAssignments as $matchID)
						{
							$temp = $id+1;
							if(!$assignment->reviewExists($matchID))
							{
								$item = new stdClass();
								$item->type = "Calibration";
								$item->assignmentID = $assignment->assignmentID;
								$item->endDate = $assignment->reviewStartDate;
								$item->html = 
								"<table width='100%'><tr><td class='column1'><h4>$assignment->name</h4></td>
								<td class='column2'>Calibration Review $temp</td>

								<td class='column3'><a href='".get_redirect_url("peerreview/editreview.php?assignmentid=$assignment->assignmentID&calibration=$id")."''><button>Go</button></a></td>
								<td class='column4'>".phpDate($assignment->reviewStopDate)."</td></tr></table>\n";
								insert($item, $items);
							}
							$id++;
						}			

						//TO-DO: Clean-up logic flow
	                	$availableCalibrationSubmissions = $assignment->getCalibrationSubmissionIDs();#$#
		                if($availableCalibrationSubmissions && $assignment->extraCalibrations > 0)
		                {					
		                    $independents = $assignment->getIndependentUsers();
							
		                    $convertedAverage = convertTo10pointScale($currentAverage, $assignment);
							
							if($assignment->submissionSettings->autoAssignEssayTopic == true && sizeof($assignment->submissionSettings->topics))
								{
									$i = topicHash($USERID, $assignment->submissionSettings->topics);
									$isMoreEssays = $assignment->getNewCalibrationSubmissionForUserRestricted($USERID, $i);
								}
							else
								$isMoreEssays = $assignment->getNewCalibrationSubmissionForUser($USERID);
							
		                    if(!isIndependent($USERID, $latestCalibrationAssignment) && $isMoreEssays != NULL)
		                    {
		                    	$doneForThisAssignment = $assignment->numCalibrationReviewsDone($USERID);
		                    	$completionStatus = "";
								if($doneForThisAssignment < $assignment->extraCalibrations)
		                    		$completionStatus .= "<br/>$doneForThisAssignment of $assignment->extraCalibrations completed";
								
								$item = new stdClass();
								$item->type = "Calibration";
								$item->assignmentID = $assignment->assignmentID;
								$item->endDate = $assignment->reviewStartDate;
		                    	$item->html = 
		                    	"<table width='100%'><tr><td class='column1'><h4>$assignment->name</h4></td>
		                    	<td class='column2'>Calibration Review $completionStatus</td>
		                    	<td class='column3'><table wdith='100%'><td>Current Average: $convertedAverage <br/> Threshold: $assignment->calibrationThresholdScore</td> 
		                    	<td><a href='".get_redirect_url("peerreview/requestcalibrationreviews.php?assignmentid=$assignment->assignmentID")."'><button>Request Calibration Review</button></a></td></table></td>
		                    	<td class='column4'>".phpDate($assignment->reviewStartDate)."</td></tr></table>\n";
								insert($item, $items);
		                   	}
		                }
		           	}
                }
			}
			
			$content .= "<h1>Tasks</h1>\n";
			if(!$items){
				$content .= "You currently have no assigned tasks";
			}
			$bg = '';
			foreach($items as $item)
			{
				$bg = ($bg == '#E0E0E0' ? '' : '#E0E0E0');
				$content .= "<div class='TODO' style='background-color:$bg;'>";
				$content .= $item->html;
				$content .= "</div>";
			}
			
			$status = "Supervised"; $reviewerAverage = "--"; $threshold = ""; $minimumReviews = "";
			if($latestCalibrationAssignment != NULL)
			{
                $reviewerAverage = convertTo10pointScale($currentAverage, $latestCalibrationAssignment); 
				if(isIndependent($USERID, $latestCalibrationAssignment))
					$status = "Independent";
				$threshold = $latestCalibrationAssignment->calibrationThresholdScore;
				$minimumReviews = $latestCalibrationAssignment->calibrationMinCount;
			}
			
			$content .= "<h1>Calibration</h1>\n";
			$color = ($status == "Independent") ? "green" : "red";
			$content .= "<h2>Current Review Status : <span style='color:$color'>".$status."</span></h2>\n";
			if($latestCalibrationAssignment)
			{
				$calibrationHistory = calibrationHistory($USERID, $latestCalibrationAssignment);
				if($calibrationHistory->hasReached)
					$content .= "<h4 style='color:green'>Promoted with score ".$calibrationHistory->score." on review no. ".$calibrationHistory->reviewNum."</h4>\n";
				if($status == "Independent")
					$content .= "<h4 style='color:green'>All calibration reviews are now for practice</h4>\n";
				if($status == "Supervised" && $demotion != NULL)
					$content .= "<h4 style='color:red'>You have been placed back into the supervised pool because the average TA grades for your reviews is lower than $demotion->demotionThreshold%</h4>\n";
			}
			$content .= "<h2>Current Weighted Average : ".$reviewerAverage."</h2>\n";
			$content .= "<h2>Threshold: ".$threshold."</h2>\n";
			$content .= "<h2>Number of Effective Calibrations Done: ".$dataMgr->numCalibrationReviews($USERID)."</h2>\n";
			$content .= "<h2>Minimum Calibrations Required: ".$minimumReviews."</h2>\n";
						
			foreach($assignments as $assignment)
			{
				$availableCalibrationSubmissionIDs = $assignment->getCalibrationSubmissionIDs();
				if($availableCalibrationSubmissionIDs)
				{
					$doneCalibrations = array();
					$unfinishedCalibrations = array();
					$calibrationAssignments = $assignment->getAssignedCalibrationReviews($USERID);
                    $id = 0;
					foreach($calibrationAssignments as $matchID)
					{
						if($assignment->reviewExists($matchID))
						{
							$mark = $assignment->getReviewMark($matchID);
							$doneCalibrations[$id] = new stdClass;
		                    if($mark->isValid){
		                        $doneCalibrations[$id]->text = "(".convertTo10pointScale($mark->reviewPoints, $assignment).")"; 
		                        $doneCalibrations[$id]->points = $mark->reviewPoints;
		                       	$review = $assignment->getReview($matchID);
								if($demotion ? $demotion->demotionDate >= $review->reviewTimestamp : false)
									$doneCalibrations[$id]->text = "<span style='color:gray'>".$doneCalibrations[$id]->text."</span>";
		                    }else{
		                        $doneCalibrations[$id]->text = "";
		                        $doneCalibrations[$id]->points = 0;
							}
						}
						else
						{
						    if($assignment->reviewDraftExists($matchID)) {
                                $unfinishedCalibrations[$id] = "In Progress";
                            } else {
                                $unfinishedCalibrations[$id] = "Not Complete";
                            }
						}
						$id = $id+1;
					}
					$content .= "<div class='calibAssign'>";
	            	$content .= "<h3>$assignment->name</h3>";
					$content .= "<table width='100%'>";
					$content .= "<tr><td width='70%'><table>";
					foreach($doneCalibrations as $id => $obj)
		            {
		                $content .= "<tr><td>";
		                $temp=$id+1;
		                $content .= "<a href='".get_redirect_url("peerreview/editreview.php?assignmentid=$assignment->assignmentID&calibration=$id")."''>Calibration Review $temp</a>";
		                $content .= "</td><td>".$obj->text."</td><tr>";
		            }
					$content .= "</table>";
					if(sizeof($unfinishedCalibrations) > 0)
					{
						$content .= "<h4>Unfinished Calibrations</h4>";
						$content .= "<table width='100%'>";
						foreach($unfinishedCalibrations as $id => $status)
						{
							$content .= "<tr><td>";
							$temp=$id+1;
							$content .= "<a href='".get_redirect_url("peerreview/editreview.php?assignmentid=$assignment->assignmentID&calibration=$id")."''>Calibration Review $temp</a>";
							$content .= "</td><td>".$status."</td><tr>";
						}
						$content .= "</table>";
					}
					$content .= "</td><td>";
					$buttonMessage = ($status=="Independent") ? "Request Practice Review" : "Request Calibration Review";
					$content .= "<td width='30%'><a href='".get_redirect_url("peerreview/requestcalibrationreviews.php?assignmentid=$assignment->assignmentID")."'><button>$buttonMessage</button></a></td>";
					$content .= '</td><tr></table>';
					$content .= "</div>";
				}
			}
		}
		
		if($dataMgr->isMarker($USERID))
		{
			require_once("tasks_TA.php");
		}

        if($dataMgr->isInstructor($USERID))
        {
            //Give them the option of creating an assignment, or running global scripts
            $content .= "<table align='left'><tr>\n";
            $content .= "<td><a title='Create new Assignment' href='".get_redirect_url("editassignment.php?action=new")."'><div class='icon new'></div></a</td>\n";
            $content .= "<td><a title='Run Scripts' href='".get_redirect_url("runscript.php")."'><div class='icon script'></div></a></td>\n";
            $content .= "<td><a title='User Manager' href='".get_redirect_url("usermanager.php")."'><div class='icon userManager'></div></a></td>\n";
            $content .= "</tr></table><br>\n";
        }
		
        $content .= "<h1>Assignments</h1>\n";
        $currentRowIndex = 0;
        foreach($assignments as $assignment)
        {
            #See if we should even display this assignment
            if(!$assignment->showForUser($USERID))
                continue;

            $rowClass = "rowType".($currentRowIndex % 2);
            $currentRowIndex++;

            #Make a div for each assignment to live in
            $content .= "<div class='box $rowClass'>\n";
            $content .= "<h3>".$assignment->name."</h3>";
            if($dataMgr->isInstructor($USERID))
            {
                #We need to give them the common options
                $content .= "<table align='left'><tr>\n";
                $content .= "<td><a title='Move Up' href='".get_redirect_url("editassignment.php?assignmentid=$assignment->assignmentID&action=moveUp")."'><div class='icon moveUp'></div></a</td>\n";
                $content .= "<td><a title='Move Down' href='".get_redirect_url("editassignment.php?assignmentid=$assignment->assignmentID&action=moveDown")."'><div class='icon moveDown'></div></a></td>\n";
                $content .= "<td><a title='Delete' href='".get_redirect_url("editassignment.php?assignmentid=$assignment->assignmentID&action=delete")."'><div class='icon delete'></div></a></td>\n";
                $content .= "<td><a title='Edit Main Settings' href='".get_redirect_url("editassignment.php?action=edit&assignmentid=$assignment->assignmentID")."'><div class='icon edit'></div></a></td>\n";
                $content .= "<td><a title='Run Scripts' href='".get_redirect_url("runscript.php?assignmentid=$assignment->assignmentID")."'><div class='icon script'></div></a></td>\n";
                $content .= "<td><a title='Duplicate Assignment' href='".get_redirect_url("editassignment.php?assignmentid=$assignment->assignmentID&action=duplicate")."'><div class='icon duplicate'></div></a></td>\n";
                $content .= "</table><br/>\n";
            }
            $content .= $assignment->getHeaderHTML($USERID);
            $content .= "</div>";
        }

        render_page();
    }
}catch(Exception $e) {
    render_exception_page($e);
}

?>
