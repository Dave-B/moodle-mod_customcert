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
 * File contains the unit tests for the email certificate task.
 *
 * @package    mod_customcert
 * @category   test
 * @copyright  2017 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Unit tests for the email certificate task.
 *
 * @package    mod_customcert
 * @category   test
 * @copyright  2017 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_customcert_task_email_certificate_task_testcase extends advanced_testcase {

    /**
     * Test set up.
     */
    public function setUp() {
        $this->resetAfterTest();
    }

    /**
     * Tests the email certificate task.
     */
    public function test_email_certificates_students() {
        global $DB;

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create some users
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        // Enrol two of them in the course as students.
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, $roleids['student']);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, $roleids['student']);

        // Enrol one of the users as a teacher.
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, $roleids['editingteacher']);

        // Create a custom certificate.
        $customcert = $this->getDataGenerator()->create_module('customcert', array('course' => $course->id,
            'emailstudents' => 1));

        // Ok, now issue this to one user.
        $customcertissue = new stdClass();
        $customcertissue->customcertid = $customcert->id;
        $customcertissue->userid = $user1->id;
        $customcertissue->code = \mod_customcert\certificate::generate_code();
        $customcertissue->timecreated = time();
        $customcertissue->emailed = 0;

        // Insert the record into the database.
        $DB->insert_record('customcert_issues', $customcertissue);

        // Confirm there is only one entry in this table.
        $this->assertEquals(1, $DB->count_records('customcert_issues'));

        // Run the task.
        $task = new \mod_customcert\task\email_certificate_task();
        $task->execute();

        // Get the issues from the issues table now.
        $issues = $DB->get_records('customcert_issues');
        $this->assertCount(2, $issues);

        // Confirm that it was not issued to the teacher.
        foreach ($issues as $issue) {
            $this->assertNotEquals($user3->id, $issue->userid);
        }
    }

    /**
     * Tests the email certificate task not visible.
     */
    public function test_email_certificates_students_not_visible() {
        global $DB;

        // Create a course.
        $course = $this->getDataGenerator()->create_course();

        // Create a user.
        $user1 = $this->getDataGenerator()->create_user();

        // Enrol them in the course.
        $roleids = $DB->get_records_menu('role', null, '', 'shortname, id');
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, $roleids['student']);

        // Create a custom certificate.
        $this->getDataGenerator()->create_module('customcert', array('course' => $course->id, 'emailstudents' => 1));

        // Remove the permission for the user to view the certificate.
        assign_capability('mod/customcert:view', CAP_PROHIBIT, $roleids['student'], \context_course::instance($course->id));

        // Run the task.
        $task = new \mod_customcert\task\email_certificate_task();
        $task->execute();

        // Confirm there are no issues as the user did not have permissions to view it.
        $issues = $DB->get_records('customcert_issues');
        $this->assertCount(0, $issues);
    }
}