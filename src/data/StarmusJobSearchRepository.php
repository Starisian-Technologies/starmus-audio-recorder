<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data;

use Starisian\Sparxstar\Starmus\data\StarmusJob;
use wpdb;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Repository for managing Starmus SageMaker jobs in the database.
 * Supports legacy custom table storage (if used) in addition to options.
 *
 * @package Starisian\Sparxstar\Starmus\data
 */
final readonly class StarmusJobSearchRepository
{
    private wpdb $db;
    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->db         = $wpdb;
        $this->table_name = $this->db->prefix . 'starmus_jobs';
    }

    public function find(string $job_id): ?StarmusJob
    {
        $row = $this->db->get_row($this->db->prepare(\sprintf('SELECT * FROM %s WHERE job_id = %%s', $this->table_name), $job_id));
        if ( ! $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Save or update a Job record in the database.
     *
     * @param StarmusJob $job The Job object to save.
     * @return bool True on success, false on failure.
     */
    public function save(StarmusJob $job): bool
    {
        $data   = [
        'job_id'        => $job->job_id,
        'post_id'       => $job->post_id,
        'status'        => $job->status,
        'attempts'      => $job->attempts,
        'file_path'     => $job->file_path,
        'error_message' => $job->error_message,
        'result'        => $job->result,
        'created_at'    => gmdate('Y-m-d H:i:s', $job->created_at),
        'finished_at'   => $job->finished_at ? gmdate('Y-m-d H:i:s', $job->finished_at) : null,
        ];
        $format = ['%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s'];

        if ($job->id) {
            return (bool) $this->db->update($this->table_name, $data, ['id' => $job->id], $format, ['%d']);
        }

        return (bool) $this->db->insert($this->table_name, $data, $format);
    }

    public function delete(string $job_id): bool
    {
        return (bool) $this->db->delete($this->table_name, ['job_id' => $job_id], ['%s']);
    }

    public function get_paged_jobs(int $page = 1, int $per_page = 20): array
    {
        $offset  = ($page - 1) * $per_page;
        $results = $this->db->get_results(
        $this->db->prepare(
        'SELECT * FROM %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
        $this->table_name,
        $per_page,
        $offset
        )
        );

        return array_map([$this, 'hydrate'], $results);
    }

    public function get_total_count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(id) FROM ' . $this->table_name);
    }

    private function hydrate(object $row): StarmusJob
    {
        return new StarmusJob(
        job_id: $row->job_id,
        post_id: (int) $row->post_id,
        status: $row->status,
        attempts: (int) $row->attempts,
        file_path: $row->file_path,
        error_message: $row->error_message,
        result: $row->result,
        created_at: strtotime((string) $row->created_at),
        finished_at: $row->finished_at ? strtotime((string) $row->finished_at) : null,
        id: (int) $row->id
        );
    }

    /**
     * Create the jobs table if it doesn't exist.
     */
    public static function install_table(): void
    {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'starmus_jobs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            job_id varchar(255) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            file_path text,
            error_message longtext,
            result longtext,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            finished_at datetime,
            PRIMARY KEY  (id),
            UNIQUE KEY job_id (job_id)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
