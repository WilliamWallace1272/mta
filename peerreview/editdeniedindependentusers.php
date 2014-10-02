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

		$independentMap = array();
        $currentRowType = 0;
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
				$independentMap[$user] = 1;
			}
			else
				$independentMap[$user] = 0;
            $content .= "<tr class='rowType$currentRowType'><td>$name</td><td><input type='checkbox' name='denied[]' value='$user' $deniedChecked /> Denied </td><td><input type='checkbox' name='independent[]' class='independent' value='$user' $independentChecked /> Independent </td></tr>\n";
            $currentRowType = ($currentRowType+1)%2;
        }
        $content .= "<tr><td>&nbsp;</td><td>\n";
		$content .= "<tr><td></td><td></td><td><input type='checkbox' name='allIndependent' id='allIndependent'/>All</td></tr>";
        $content .= "</table>\n";
        $content .= "<br><input type='submit' value='Save' />\n";
        $content .= "</form>\n";

		$content .= "<script type='text/javascript'>";
		$content .= "var independent = new Array();";
		foreach($independentMap as $user => $isIndependent)
			$content .= "independent[$user] = $isIndependent;";
		$content .= "</script>";
	
		$content .= "<script type='text/javascript'>		
        $('#allIndependent').change(function(){
			if(this.checked){
				$('.independent').prop('checked', true);
			}else{
				$('.independent').prop('checked', false);
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
