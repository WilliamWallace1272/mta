<?php
require_once("inc/common.php");
try
{
    $title .= " | Edit Denied/Independent Reviewers";
    $authMgr->enforceInstructor();
    $dataMgr->requireCourse();

    $assignment = get_peerreview_assignment();
    #Depending on the action, we do different things
    $action = '';
    if(array_key_exists('action', $_GET)) {
        $action = $_GET['action'];
    }

    if($action == 'save'){
        #See if we can do the save
        if(array_key_exists('denied', $_POST))
            $assignment->saveDeniedUsers($_POST['denied']);
        else
            $assignment->saveDeniedUsers(array());
        if(array_key_exists('independent', $_POST))
            $assignment->saveIndependentUsers($_POST['independent']);
        else
            $assignment->saveIndependentUsers(array());

        #We're good, go to home
        redirect_to_main();
    }
    else
    {
        $content .= '<h1>Edit Denied/Independent Reviewers</h1>';

        $content .= "<form id='users' action='?assignmentid=$assignment->assignmentID&action=save' method='post'>";
        $content .= "<table align='left' width='100%'>";

        $deniedUsers = $assignment->getDeniedUsers();
        $independentUsers = $assignment->getIndependentUsers();

		$independentsRecord = array();
        $currentRowType = 0;
		$i = 0;
        foreach($dataMgr->getUserDisplayMap() as $user => $name ){
            if(!$dataMgr->isStudent(new UserID($user)))
                continue;

            $deniedChecked = '';
            $independentChecked = '';
            if(array_key_exists($user, $deniedUsers))
                $deniedChecked = 'checked';
            if(array_key_exists($user, $independentUsers))
			{
                $independentChecked = 'checked';
				$independentsRecord[$i] = 1;
			}
			else
				$independentsRecord[$i] = 0;
            $content .= "<tr class='rowType$currentRowType'><td>$name</td><td><input type='checkbox' name='denied[]' value='$user' $deniedChecked /> Denied </td><td><input type='checkbox' name='independent[]' id='independent$i' class='independents' value='$user' $independentChecked /> Independent </td></tr>\n";
            $currentRowType = ($currentRowType+1)%2;
			$i++;
        }
        $content .= "<tr><td>&nbsp;</td><td>\n";
		$content .= "<tr><td></td><td></td><td><input type='checkbox' name='allIndependent' id='allIndependent'/>Select All</td></tr>";
        $content .= "</table>\n";
        $content .= "<br><input type='submit' value='Save' />\n";
        $content .= "</form>\n";

		$content .= "<script type='text/javascript'>";
		$content .= "var independent = new Array();";
		$i = 0;
		foreach($independentsRecord as $isIndependent)
		{
			$content .= "independent[$i] = $isIndependent;";
			$i++;
		}
        $content .= "$('#allIndependent').change(function(){
			if(this.checked){
				$('.independents').prop('checked', true);
			}else{
				for(i = 0, len = independent.length; i < len; i++)
				{
					if(independent[i]==1)
						$('#independent' + i.toString()).prop('checked', true);
					else
						$('#independent' + i.toString()).prop('checked', false);	
				}
			}
        });
        $('#courseSelect').change();
        </script>\n";

        render_page();
    }
}catch(Exception $e){
    render_exception_page($e);
}
?>
