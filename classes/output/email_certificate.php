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
 * Email certificate renderable.
 *
 * @package    mod_customcert
 * @copyright  2017 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_customcert\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Email certificate renderable.
 *
 * @copyright  2017 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email_certificate implements \renderable, \templatable {

    /**
     * Email type student constant.
     */
    const EMAIL_TYPE_STUDENT = 1;

    /**
     * Email type teacher constant.
     */
    const EMAIL_TYPE_TEACHER = 2;

    /**
     * Email type other constant.
     */
    const EMAIL_TYPE_OTHER = 3;

    /**
     * @var int The email type.
     */
    public $emailtype;

    /**
     * @var string The name of the user who owns the certificate.
     */
    public $userfullname;

    /**
     * @var string The course full name.
     */
    public $coursefullname;

    /**
     * @var int The certificate name.
     */
    public $certificatename;

    /**
     * Constructor.
     *
     * @param int $emailtype The type of email we are sending.
     * @param string $userfullname The name of the user who owns the certificate.
     * @param string $coursefullname The name of the course.
     * @param string $certificatename The name of the certificate.
     */
    public function __construct($emailtype, $userfullname, $coursefullname, $certificatename) {
        $this->emailtype = $emailtype;
        $this->userfullname = $userfullname;
        $this->coursefullname = $coursefullname;
        $this->certificatename = $certificatename;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param \renderer_base $renderer The render to be used for formatting the email
     * @return \stdClass The data ready for use in a mustache template
     */
    public function export_for_template(\renderer_base $renderer) {
        $data = new \stdClass();
        $data->isstudent = false;
        $data->isteacher = false;
        $data->isother = false;

        if ($this->emailtype == self::EMAIL_TYPE_STUDENT) {
            $data->isstudent = true;
        } else if ($this->emailtype == self::EMAIL_TYPE_TEACHER) {
            $data->isteacher = true;
        } else {
            $data->isother = true;
        }

        $data->userfullname = $this->userfullname;
        $data->coursefullname = $this->coursefullname;
        $data->certificatename = $this->certificatename;

        return $data;
    }
}
