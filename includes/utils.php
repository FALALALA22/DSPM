<?php
// Helper functions
function calculateAgeFromDOB($dob) {
    try {
        $dobDt = new DateTime($dob);
    } catch (Exception $e) {
        return array('years' => 0, 'months' => 0, 'days' => 0);
    }
    $now = new DateTime();
    $diff = $now->diff($dobDt);
    return array('years' => $diff->y, 'months' => $diff->m, 'days' => $diff->d);
}

?>
