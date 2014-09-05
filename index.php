<?php
require_once("inc/common.php");
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
			if($scores = $dataMgr->getCalibrationScores($USERID))
				$currentAverage = computeWeightedAverage($scores);
			else 
				$currentAverage = "--";
			
			$output = array();
			$items = array();
			
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
						"<table width='100%' align='left'><tr><td class='column1'><h4>$assignment->name</h4></td>
						<td class='column2'>Password</td></td>
						<td class='column3A'>Enter password:<form action='enterpassword.php?assignmentid=".$assignment->assignmentID."' method='post'><input type='text' name='password' size='10'/></td>
						<td class='column3B'><input type='submit' value='Enter'/></form></td>
						<td class='column4'>".date('M jS Y, H:i', $assignment->submissionStopDate)."</td></tr></table>\n";
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
							"<table width='100%' align='left'><tr><td class='column1'><h4>$assignment->name</h4></td>
							<td class='column2'>".ucfirst($assignment->submissionType)."</td>
							
							<td class='column3'><form action='".get_redirect_url("peerreview/editsubmission.php?assignmentid=$assignment->assignmentID")."' method='post'><input type='submit' value='Create Submission'/></form></td>
							<td class='column4'>".date('M jS Y, H:i', $assignment->submissionStopDate)."</td></tr></table>\n";
							insert($item, $items);
						}
					}	
				}

				if($assignment->reviewStartDate <= $NOW AND $assignment->reviewStopDate > $NOW)
				{
					if($assignment->password == NULL || $dataMgr->hasEnteredPassword($assignment->assignmentID, $USERID))
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
								"<table width='100%' align='left'><tr><td class='column1'><h4>$assignment->name</h4></td>
								<td class='column2'>Peer Review $temp</td>
								
								<td class='column3'><a href='".get_redirect_url("peerreview/editreview.php?assignmentid=$assignment->assignmentID&review=$id")."''><button>Go</button></a></td>
								<td class='column4'>".date('M jS Y, H:i', $assignment->reviewStopDate)."</td></tr></table>\n";
								insert($item, $items);
							}
							$id++;			
						} 
					
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
								$item->endDate = $assignment->reviewStopDate;
								$item->html = 
								"<table width='100%' align='left'><tr><td class='column1'><h4>$assignment->name</h4></td>
								<td class='column2'>Calibration Review $temp</td>

								<td class='column3'><a href='".get_redirect_url("peerreview/editreview.php?assignmentid=$assignment->assignmentID&calibration=$id")."''><button>Go</button></a></td>
								<td class='column4'>".date('M jS Y, H:i', $assignment->reviewStopDate)."</td></tr></table>\n";
								insert($item, $items);
							}
							$id++;
						}			
					
						//TO-DO: Clean-up logic flow
	                	$availableCalibrationSubmissions = $assignment->getCalibrationSubmissionIDs();#$#
		                if($availableCalibrationSubmissions && $assignment->extraCalibrations > 0)
		                {					
		                    $independents = $assignment->getIndependentUsers();
							
		                    //if student is supervised and has done less than the extra calibrations required
		                    if($currentAverage != "--") 
		                    	$convertedAverage = convertTo10pointScale($currentAverage, $assignment); 
		                    else 
		                   		$convertedAverage = $currentAverage;
							
							if($assignment->submissionSettings->autoAssignEssayTopic == true && sizeof($assignment->submissionSettings->topics))
								{
									$i = topicHash($USERID, $assignment->submissionSettings->topics);
									$isMoreEssays = $assignment->getNewCalibrationSubmissionForUserRestricted($USERID, $i);
								}
							else
								$isMoreEssays = $assignment->getNewCalibrationSubmissionForUser($USERID);

								$doneForThisAssignment = $assignment->numCalibrationReviewsDone($USERID);
								$enoughScore = ($convertedAverage != "--" && $convertedAverage >= $assignment->calibrationThresholdScore);
								$enoughReviews = $doneForThisAssignment >= $assignment->calibrationMinCount;
								$enough = $enoughScore && $enoughReviews;
								
		                    if(!array_key_exists($USERID->id, $independents) && !$enough && $isMoreEssays != NULL)
		                    {
		                    	$completionStatus = "";
								if($doneForThisAssignment < $assignment->extraCalibrations)
		                    		$completionStatus .= "<br/>".$doneForThisAssignment." of $assignment->extraCalibrations completed";
								
								if($isMoreEssays)
									$moreCalibrations = "<td class='column3A'><a href='".get_redirect_url("peerreview/requestcalibrationreviews.php?assignmentid=$assignment->assignmentID")."'><button>Request Calibration Review</button></a></td>";
								else 
									$moreCalibrations = "<td class='column3A'>No more available calibrations</td>";
								
								$item = new stdClass();
								$item->type = "Calibration";
								$item->assignmentID = $assignment->assignmentID;
								$item->endDate = $assignment->reviewStopDate;
		                    	$item->html = 
		                    	"<table width='100%' align='left'><tr><td class='column1'><h4>$assignment->name</h4></td>
		                    	<td class='column2'>Calibration Review $completionStatus</td>
		                    	<td class='column3A'>Current Average: $convertedAverage <br/> Threshold: $assignment->calibrationThresholdScore</td> 
		                    	$moreCalibrations
		                    	<td class='column4'>".date('M jS Y, H:i', $assignment->reviewStopDate)."</td></tr></table>\n";
								insert($item, $items);
		                   	}
		                }
		           	}
                }
			}
			
			$content .= "<h1>TODO</h1>\n";
			$content .= "<table width='100%' align='left'>";
			$bg = '#eeeeee';
			foreach($items as $item)
			{
				$bg = ($bg == '#eeeeee' ? '#ffffff' : '#eeeeee');
				$content .= "<div width='100%' height='150px' style='background-color:$bg'>";
				$content .= $item->html;
				$content .= "</div>";
			}
			$content .= "</table>";
			
			$latestCalibrationID = NULL;
			foreach($assignments as $assignment)
			{
				if($assignment->extraCalibrations > 0)
				{
					/*if($latestCalibrationID != NULL)
						$latestCalibrationID = $assignment->assignmentID;
					$latestCalibrationAssignment = $dataMgr->getAssignment($latestCalibrationID);
					if($latestCalibrationAssignment->reviewEndDate < $assignment->reviewEndDate)
						$latestCalibrationID = $assignment->assignmentID;*/
				}
			}
			//Use another way to get latest effective Review Assignment
			
			if($latestCalibrationID != NULL)
			{
				$latestCalibrationAssignment = $dataMgr->getAssignment($latestCalibrationID);
			    if($currentAverage != "--") 
                	$reviewerAverage = convertTo10pointScale($currentAverage, $latestCalibrationAssignment); 
                else 
               		$reviewerAverage = $currentAverage;
				$status = "";
				if($reviewAverage == "--"|| $reviewerAverage < $latestCalibrationAssignment->calibrationThresholdScore)
					$status = "Supervised";
				else
					$status = "Independent";
			}
			
			$content .= "<h1>Calibration</h1>\n";
			$content .= "<h2>Current Review Status : ".$status."</h2>";
			$content .= "<h2>Current Weighted Average : ".$reviewerAverage."</h2>";
			$content .= "<h2>Threshold: ".$latestCalibrationAssignment->calibrationThresholdScore."</h2>";
						
			$bg = '#eeeeee';
			foreach($assignments as $assignment)
			{
				$calibrationAssignments = $assignment->getAssignedCalibrationReviews($USERID);
				$doneCalibrations = array();
				$unfinishedCalibrations = array();
				if($calibrationAssignments)
				{
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
					$bg = ($bg == '#eeeeee' ? '#ffffff' : '#eeeeee');
					$content .= "<div class='calbAssign' 'style='background-color:$bg;'>";
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
					$content .= "<td width='30%'><a href='".get_redirect_url("peerreview/requestcalibrationreviews.php?assignmentid=$assignment->assignmentID")."'><button>Request Calibration Review</button></a></td>";
					$content .= '</td><tr></table>';
					$content .= "</div>";
				}
			}
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

function insert($object, &$array)
{
	$length = sizeof($array);
	for($i = 0; $i <= $length; $i++)
	{
		if($array[$i] == NULL)
			$array[$i] = $object;
		if($object->endDate < $array[$i]->endDate)
		{
			for($j = $length; $j > $i; $j--)
			{
				$array[$j] = $array[$j-1];
			}
			$array[$i] = $object;
			break;
		}
	}
}

?>

