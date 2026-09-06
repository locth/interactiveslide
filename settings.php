<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Site wide settings for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_heading(
        'mod_interactiveslide/difficultyheading',
        get_string('settingsdifficulty', 'mod_interactiveslide'),
        get_string('settingsdifficulty_desc', 'mod_interactiveslide')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_interactiveslide/points_easy',
        get_string('difficultyeasy', 'mod_interactiveslide'),
        get_string('points_easy_desc', 'mod_interactiveslide'),
        1,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_interactiveslide/points_medium',
        get_string('difficultymedium', 'mod_interactiveslide'),
        get_string('points_medium_desc', 'mod_interactiveslide'),
        2,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_interactiveslide/points_hard',
        get_string('difficultyhard', 'mod_interactiveslide'),
        get_string('points_hard_desc', 'mod_interactiveslide'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'mod_interactiveslide/liveheading',
        get_string('settingslive', 'mod_interactiveslide'),
        get_string('settingslive_desc', 'mod_interactiveslide')
    ));

    $settings->add(new admin_setting_configselect(
        'mod_interactiveslide/pollinterval',
        get_string('pollinterval', 'mod_interactiveslide'),
        get_string('pollinterval_desc', 'mod_interactiveslide'),
        2000,
        [
            1000 => get_string('pollinterval1', 'mod_interactiveslide'),
            2000 => get_string('pollinterval2', 'mod_interactiveslide'),
            3000 => get_string('pollinterval3', 'mod_interactiveslide'),
            5000 => get_string('pollinterval5', 'mod_interactiveslide'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_interactiveslide/renderscale',
        get_string('renderscale', 'mod_interactiveslide'),
        get_string('renderscale_desc', 'mod_interactiveslide'),
        1600,
        [
            1200 => '1200 px',
            1600 => '1600 px',
            2000 => '2000 px',
            2400 => '2400 px',
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_interactiveslide/imageformat',
        get_string('imageformat', 'mod_interactiveslide'),
        get_string('imageformat_desc', 'mod_interactiveslide'),
        'jpeg85',
        [
            'jpeg85' => get_string('imageformatjpeg85', 'mod_interactiveslide'),
            'jpeg70' => get_string('imageformatjpeg70', 'mod_interactiveslide'),
            'png' => get_string('imageformatpng', 'mod_interactiveslide'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_interactiveslide/studentslides',
        get_string('studentslides', 'mod_interactiveslide'),
        get_string('studentslides_desc', 'mod_interactiveslide'),
        1,
        [
            1 => get_string('studentslidesshow', 'mod_interactiveslide'),
            0 => get_string('studentslideshide', 'mod_interactiveslide'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'mod_interactiveslide/maxpages',
        get_string('maxpages', 'mod_interactiveslide'),
        get_string('maxpages_desc', 'mod_interactiveslide'),
        200,
        PARAM_INT
    ));
}
