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
 * This file contains the form add/update a competency framework.
 *
 * @package   tool_lpimportau
 * @copyright 2015 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_lpimportau\form;

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

use moodleform;
use tool_lp\api;

require_once($CFG->libdir.'/formslib.php');

/**
 * Import Competency framework form.
 *
 * @package   tool_lp
 * @copyright 2015 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import extends \tool_lp\form\competency_framework {

    /**
     * Define the form - called by parent constructor
     */
    public function definition_after_data() {
        $mform = $this->_form;
        $element = $mform->createElement('filepicker', 'importfile', get_string('importfile', 'tool_lpimportau'));
        $mform->insertElementBefore($element, 'idnumber');
        $mform->addRule('importfile', null, 'required');
    }

    public function get_submitted_data() {
        // The field importfile mucks up the default validation from the parent form.
        $data = parent::get_submitted_data();
        unset($data->importfile);
        return $data;
    }

    public function set_import_error($msg) {
        $mform = $this->_form;

        $mform->setElementError('importfile', $msg);
    }

}
