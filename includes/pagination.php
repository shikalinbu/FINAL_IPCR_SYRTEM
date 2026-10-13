<?php
/**
 * Pagination Helper Functions
 * Reusable pagination logic for all tables
 */

/**
 * Calculate pagination values
 * 
 * @param int $totalRecords Total number of records
 * @param int $recordsPerPage Records to show per page
 * @param int $currentPage Current page number
 * @return array Pagination data
 */
function calculatePagination($totalRecords, $recordsPerPage = 20, $currentPage = 1) {
    $totalPages = max(1, ceil($totalRecords / $recordsPerPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $recordsPerPage;
    
    return [
        'total_records' => $totalRecords,
        'records_per_page' => $recordsPerPage,
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'offset' => $offset,
        'has_previous' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages,
        'previous_page' => max(1, $currentPage - 1),
        'next_page' => min($totalPages, $currentPage + 1),
        'showing_from' => $totalRecords > 0 ? $offset + 1 : 0,
        'showing_to' => min($offset + $recordsPerPage, $totalRecords)
    ];
}

/**
 * Generate pagination HTML
 * 
 * @param array $pagination Pagination data from calculatePagination()
 * @param array $queryParams Additional URL query parameters to preserve
 * @return string HTML for pagination
 */
function renderPagination($pagination, $queryParams = []) {
    if ($pagination['total_pages'] <= 1) {
        return ''; // No pagination needed
    }
    
    // Build base URL with preserved query params
    $baseUrl = $_SERVER['PHP_SELF'];
    $params = array_merge($_GET, $queryParams);
    unset($params['page']); // Remove page param, we'll add it separately
    
    $queryString = http_build_query($params);
    $baseUrl .= $queryString ? '?' . $queryString . '&' : '?';
    
    $current = $pagination['current_page'];
    $total = $pagination['total_pages'];
    
    $html = '<div class="pagination-wrapper">';
    
    // Showing X-Y of Z records
    $html .= '<div class="pagination-info">';
    $html .= 'Showing ' . $pagination['showing_from'] . '-' . $pagination['showing_to'];
    $html .= ' of ' . $pagination['total_records'] . ' records';
    $html .= '</div>';
    
    $html .= '<div class="pagination-controls">';
    
    // Previous button
    if ($pagination['has_previous']) {
        $html .= '<a href="' . $baseUrl . 'page=' . $pagination['previous_page'] . '" class="pagination-btn">';
        $html .= '<i class="bi bi-chevron-left"></i> Previous';
        $html .= '</a>';
    } else {
        $html .= '<span class="pagination-btn disabled">';
        $html .= '<i class="bi bi-chevron-left"></i> Previous';
        $html .= '</span>';
    }
    
    // Page numbers
    $html .= '<div class="pagination-pages">';
    
    // Always show first page
    if ($current > 3) {
        $html .= '<a href="' . $baseUrl . 'page=1" class="pagination-page">1</a>';
        if ($current > 4) {
            $html .= '<span class="pagination-ellipsis">...</span>';
        }
    }
    
    // Show pages around current page
    $start = max(1, $current - 2);
    $end = min($total, $current + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current) {
            $html .= '<span class="pagination-page active">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $baseUrl . 'page=' . $i . '" class="pagination-page">' . $i . '</a>';
        }
    }
    
    // Always show last page
    if ($current < $total - 2) {
        if ($current < $total - 3) {
            $html .= '<span class="pagination-ellipsis">...</span>';
        }
        $html .= '<a href="' . $baseUrl . 'page=' . $total . '" class="pagination-page">' . $total . '</a>';
    }
    
    $html .= '</div>';
    
    // Next button
    if ($pagination['has_next']) {
        $html .= '<a href="' . $baseUrl . 'page=' . $pagination['next_page'] . '" class="pagination-btn">';
        $html .= 'Next <i class="bi bi-chevron-right"></i>';
        $html .= '</a>';
    } else {
        $html .= '<span class="pagination-btn disabled">';
        $html .= 'Next <i class="bi bi-chevron-right"></i>';
        $html .= '</span>';
    }
    
    $html .= '</div>'; // pagination-controls
    $html .= '</div>'; // pagination-wrapper
    
    return $html;
}

/**
 * Quick pagination - simpler version for small tables
 * 
 * @param array $pagination Pagination data
 * @return string Compact pagination HTML
 */
function renderCompactPagination($pagination) {
    if ($pagination['total_pages'] <= 1) {
        return '';
    }
    
    $current = $pagination['current_page'];
    $total = $pagination['total_pages'];
    
    $params = $_GET;
    unset($params['page']);
    $queryString = http_build_query($params);
    $baseUrl = $_SERVER['PHP_SELF'] . ($queryString ? '?' . $queryString . '&' : '?');
    
    $html = '<div class="pagination-compact">';
    $html .= '<span class="text-muted">Page ' . $current . ' of ' . $total . '</span>';
    
    if ($pagination['has_previous']) {
        $html .= '<a href="' . $baseUrl . 'page=' . $pagination['previous_page'] . '" class="btn btn-sm btn-outline-secondary">';
        $html .= '<i class="bi bi-chevron-left"></i>';
        $html .= '</a>';
    }
    
    if ($pagination['has_next']) {
        $html .= '<a href="' . $baseUrl . 'page=' . $pagination['next_page'] . '" class="btn btn-sm btn-outline-secondary">';
        $html .= '<i class="bi bi-chevron-right"></i>';
        $html .= '</a>';
    }
    
    $html .= '</div>';
    
    return $html;
}
