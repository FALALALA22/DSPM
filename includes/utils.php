<?php
// Helper functions
function calculateAgeFromDOB(string $dob): array {
    try {
        $dobDt = new DateTime($dob);
    } catch (Exception $e) {
        return ['years' => 0, 'months' => 0, 'days' => 0];
    }
    $now = new DateTime();
    $diff = $now->diff($dobDt);
    return ['years' => $diff->y, 'months' => $diff->m, 'days' => $diff->d];
}

?>
