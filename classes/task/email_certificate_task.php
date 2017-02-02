<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A scheduled task for customcert cron.
 *
 * @package    mod_customcert
 * @copyright  2017 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_customcert\task;

defined('MOODLE_INTERNAL') || die();

class email_certificate_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskemailcertificate', 'customcert');
    }

    /**
     * Run cron.
     */
    public function execute() {
        global $DB, $PAGE;

        // Get all the certificates that have requested someone get emailed.
        $sql = "SELECT c.*, ct.id as templateid, ct.name as templatename, ct.contextid, co.id as courseid,
                       co.shortname as coursename
                  FROM {customcert} c
                  JOIN {customcert_templates} ct
                    ON c.templateid = ct.id
                  JOIN {course} co
                    ON c.course = co.id
                 WHERE (c.emailstudents = :emailstudents
                        OR c.emailteachers = :emailteachers
                        OR c.emailothers != '')";
        if ($customcerts = $DB->get_records_sql($sql, array('emailstudents' => 1, 'emailteachers' => 1))) {
            // The renderers used for sending emails.
            $htmlrenderer = $PAGE->get_renderer('mod_customcert', 'email', 'htmlemail');
            $textrenderer = $PAGE->get_renderer('mod_customcert', 'email', 'textemail');
            foreach ($customcerts as $customcert) {
                // Get a list of issues that have not yet been emailed.
                $userfields = get_all_user_name_fields(true, 'u');
                $sql = "SELECT u.id, u.username, $userfields, u.email, ci.id as issueid
                          FROM {customcert_issues} ci
                          JOIN {user} u
                            ON ci.userid = u.id
                         WHERE ci.customcertid = :customcertid
                           AND emailed = :emailed";
                $issuedusers = $DB->get_records_sql($sql, array('customcertid' => $customcert->id,
                    'emailed' => 0));

                // Now, get a list of users who can access the certificate but have not yet.
                $nonissuedusers = array();
                $enrolledusers = get_enrolled_users(\context_course::instance($customcert->courseid));
                foreach ($enrolledusers as $enroluser) {
                    // Check if the user has already been issued.
                    if (in_array($enroluser->id, array_keys((array) $issuedusers))) {
                        continue;
                    }

                    // Now check if the certificate is not visible to the current user.
                    $cm = get_fast_modinfo($customcert->courseid, $enroluser->id)->instances['customcert'][$customcert->id];
                    if (!$cm->uservisible) {
                        continue;
                    }

                    // Ok, issue them the certificate.
                    $customcertissue = new \stdClass();
                    $customcertissue->customcertid = $customcert->id;
                    $customcertissue->userid = $enroluser->id;
                    $customcertissue->code = \mod_customcert\certificate::generate_code();
                    $customcertissue->emailed = 0;
                    $customcertissue->timecreated = time();

                    // Insert the record into the database.
                    $issueid = $DB->insert_record('customcert_issues', $customcertissue);

                    // Add them to the array so we email them.
                    $enroluser->issueid = $issueid;
                    $nonissuedusers[] = $enroluser;
                }

                // Now, email the people we need to.
                if ($issuedusers || $nonissuedusers) {
                    $users = $issuedusers + $nonissuedusers;
                    foreach ($users as $user) {
                        // Get the context.
                        $context = \context::instance_by_id($customcert->contextid);

                        // Get the person we are going to send this email on behalf of.
                        // Look through the teachers.
                        if ($teachers = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
                            '', '', '', '', false, true)) {
                            $teachers = sort_by_roleassignment_authority($teachers, $context);
                            $userfrom = array_shift($teachers);
                        } else { // Ok, no teachers, use administrator name.
                            $userfrom = get_admin();
                        }

                        // Get all the info we need to create strings.
                        $info = new \stdClass;
                        $info->userfullname = fullname($user);
                        $info->coursename = format_string($customcert->coursename, true, array('context' => $context));
                        $info->certificatename = format_string($customcert->name, true, array('context' => $context));

                        // Create a directory to store the PDF we will be sending.
                        $requestdir = make_request_directory();
                        if (!$requestdir) {
                            return false;
                        }

                        // Now, get the PDF.
                        $template = new \stdClass();
                        $template->id = $customcert->templateid;
                        $template->name = $customcert->templatename;
                        $template->contextid = $customcert->contextid;
                        $template = new \mod_customcert\template($template);
                        $filecontents = $template->generate_pdf(false, $user->id, true);

                        // Set the name of the file we are going to send.
                        $filename = $info->coursename . '_' . $info->certificatename;
                        $filename = \core_text::entities_to_utf8($filename);
                        $filename = strip_tags($filename);
                        $filename = rtrim($filename, '.');
                        $filename = str_replace('&', '_', $filename);

                        // Get the file to send.
                        $tempfile = $requestdir . '/' . md5(sesskey() . microtime() . $user->id . '.pdf');
                        $fp = fopen($tempfile, 'w+');
                        fputs($fp, $filecontents);
                        fclose($fp);

                        if ($customcert->emailstudents) {
                            $renderable = new \mod_customcert\output\email_certificate(
                                \mod_customcert\output\email_certificate::EMAIL_TYPE_STUDENT, $info->userfullname,
                                    $info->coursename, $info->certificatename);

                            $subject = get_string('emailstudentsubject', 'customcert', $info);
                            $message = $textrenderer->render($renderable);
                            $messagehtml = $htmlrenderer->render($renderable);
                            email_to_user($user, fullname($userfrom), $subject, $message, $messagehtml, $tempfile, $filename);
                        }

                        if ($customcert->emailteachers) {
                            $renderable = new \mod_customcert\output\email_certificate(
                                \mod_customcert\output\email_certificate::EMAIL_TYPE_TEACHER, $info->userfullname,
                                    $info->coursename, $info->certificatename);

                            $subject = get_string('emailteachersubject', 'customcert', $info);
                            $message = $textrenderer->render($renderable);
                            $messagehtml = $htmlrenderer->render($renderable);
                            foreach ($teachers as $teacher) {
                                email_to_user($teacher, fullname($userfrom), $subject, $message, $messagehtml, $tempfile, $filename);
                            }
                        }

                        if (!empty($customcert->emailothers)) {
                            $others = explode(',', $customcert->emailothers);
                            foreach ($others as $email) {
                                $email = trim($email);
                                if (validate_email($email)) {
                                    $renderable = new \mod_customcert\output\email_certificate(
                                        \mod_customcert\output\email_certificate::EMAIL_TYPE_OTHER, $info->userfullname,
                                            $info->coursename, $info->certificatename);

                                    $subject = get_string('emailothersubject', 'customcert', $info);
                                    $message = $textrenderer->render($renderable);
                                    $messagehtml = $htmlrenderer->render($renderable);

                                    $emailuser = new \stdClass();
                                    $emailuser->id = -1;
                                    $emailuser->email = $email;
                                    email_to_user($emailuser, fullname($userfrom), $subject, $message, $messagehtml, $tempfile, $filename);
                                }
                            }
                        }

                        // Set the field so that it is emailed.
                        // $DB->set_field('customcert_issues', 'emailed', 1, array('id' => $user->issueid));
                    }
                }
            }
        }
    }
}
