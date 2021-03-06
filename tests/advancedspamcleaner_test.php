<?php
// This file is part of Advanced Spam Cleaner tool for Moodle
//
// Advanced Spam Cleaner is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Advanced Spam Cleaner is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// For a copy of the GNU General Public License, see <http://www.gnu.org/licenses/>.

global $CFG;
require_once("$CFG->dirroot/$CFG->admin/tool/advancedspamcleaner/lib.php");
require_once("$CFG->dirroot/blog/lib.php");
require_once("$CFG->dirroot/blog/locallib.php");
/**
 * Class test_tool_advancedspamcleaner Test calss for advanced_spam_cleaner
 */
class tool_advancedspamcleaner_advancedspamcleaner_testcase extends advanced_testcase {

    public function test_plugin_list() {

        $spamcleaner = new advanced_spam_cleaner();
        $list = $spamcleaner->plugin_list(context_system::instance());

        // Update this if more plugins are added.
        $this->assertSame(array('akismet'), array_keys($list));
    }

    public function test_keyword_spam_search() {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $spamcleaner = new advanced_spam_cleaner();
        $manager = new tool_advancedspamcleaner_manager();
        $keywords = $manager::AUTO_KEYWORDS;
        $keywordfull = array();
        $params = array('userid' => $USER->id, 'start' => 0, 'end' => time());
        $i = 0;

        foreach ($keywords as $keyword) {
            $keywordfull[] = $DB->sql_like('description', ':descpat'.$i, false);
            $params['descpat'.$i] = "%$keyword%";
            $i++;
        }
        $conditions = '( '.implode(' OR ', $keywordfull).' ) AND u.timemodified > :start AND u.timemodified < :end';
        $sql  = "SELECT * FROM {user} u
                  WHERE deleted = 0
                    AND id <> :userid
                    AND $conditions";  // Exclude oneself.
        phpunit_util::call_internal_method($spamcleaner, "keyword_spam_search",
                array($sql, $params, 'userdesc', 'description', 'id'), "advanced_spam_cleaner");
        $this->assertSame(array(),
            phpunit_util::call_internal_method($spamcleaner, "get_spamusers", array(), "advanced_spam_cleaner")); // No content so far.

        $record = new stdClass();
        $record->description = "All things that play poker, like poker.";
        $user = $this->getDataGenerator()->create_user($record);
        $params['end'] += 1000; // Make sure time is not a issue.
        phpunit_util::call_internal_method($spamcleaner, "keyword_spam_search",
            array($sql, $params, 'userdesc', 'description', 'id'), "advanced_spam_cleaner");
        $spamusers = phpunit_util::call_internal_method($spamcleaner, "get_spamusers", array(), "advanced_spam_cleaner");
        $this->assertEquals(1, count($spamusers));
        $spam = array_pop($spamusers);
        $this->assertEquals($user->id, $spam['user']->id);
        $this->assertEquals(1, $spam['spamcount']);
        $spamtext = array_pop($spam['spamtext']);
        $this->assertSame(array('userdesc', $user->description, $user->id), $spamtext);
    }

    public function test_spam_search() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $spamcleaner = new advanced_spam_cleaner();
        $manager = new tool_advancedspamcleaner_manager();
        $keywords = array("poker", "whatever");

        // Create a spamming user.
        $record = new stdClass();
        $record->description = "All things that play poker, like poker.";
        $user = $this->getDataGenerator()->create_user($record);

        // Create a non spamming user.
        $record = new stdClass();
        $record->description = "Better to be a geek, than being an idiot.";
        $user2 = $this->getDataGenerator()->create_user($record);

        // Test user description spam search test.
        $data = new stdClass();
        $data->searchusers = true;
        $spamusers = $spamcleaner->search_spammers($data, $keywords, 0, time() + 1000, true);
        $this->assertEquals(1, count($spamusers));
        $spam = array_pop($spamusers);
        $this->assertEquals($user->id, $spam['user']->id);
        $this->assertEquals(1, $spam['spamcount']);
        $spamtext = array_pop($spam['spamtext']);
        $this->assertSame(array('userdesc', $user->description, $user->id), $spamtext);

        // Test Messages.
        $message = new stdClass();
        $message->useridfrom = $user2->id;
        $message->useridto = $user->id;
        $message->subject = "This is subject";
        $message->fullmessage = "Let us play poker tonight";
        $message->timecreated = time();
        $message->conversationid = 3;
        $mid = $DB->insert_record("messages", $message);
        $data->searchusers = false;
        $data->searchmsgs = true;
        $spamusers = $spamcleaner->search_spammers($data, $keywords, 0, time() + 1000, true);
        $this->assertEquals(1, count($spamusers));
        $spam = array_pop($spamusers);
        $this->assertEquals($user2->id, $spam['user']->id);
        $this->assertEquals(1, $spam['spamcount']);
        $spamtext = array_pop($spam['spamtext']);
        $this->assertSame(array('message', $message->fullmessage, "$mid"), $spamtext);

        // Test that oneself is always excluded.
        $this->setUser($user2->id);
        $data->searchusers = false;
        $data->searchmsgs = true;
        $spamusers = $spamcleaner->search_spammers($data, $keywords, 0, time() + 1000, true);
        $this->assertEquals(0, count($spamusers));
        $this->setAdminUser();

        // Test comments.
        $params = new stdClass();
        $params->contextid = context_system::instance()->id;
        $params->commentarea = 'phpunit';
        $params->itemid = '1';
        $params->timecreated = time();
        $params->format = FORMAT_MOODLE;
        $params->content = $user->username.'comment'; // Normal comment.
        $params->userid = $user->id;
        $DB->insert_record('comments', $params);
        $params->content = $user2->username.'comment poker'; // Spam comment.
        $params->userid = $user2->id;
        $cid = $DB->insert_record('comments', $params);

        $data->searchusers = false;
        $data->searchmsgs = false;
        $data->searchcomments = true;
        $spamusers = $spamcleaner->search_spammers($data, $keywords, 0, time() + 1000, true);
        $this->assertEquals(1, count($spamusers));
        $spam = array_pop($spamusers);
        $this->assertEquals($user2->id, $spam['user']->id);
        $this->assertEquals(1, $spam['spamcount']);
        $spamtext = array_pop($spam['spamtext']);
        $this->assertSame(array('comment', $params->content, "$cid"), $spamtext);

        // TODO: Test forum posts

        // Test blog subject.
        $blog = new blog_entry();
        $blog->subject = "comment poker"; // Spam entry.
        $blog->userid = $user->id;
        $states = blog_entry::get_applicable_publish_states();
        $blog->publishstate = reset($states);
        $blog->add();
        $spamblogid = $blog->id;

        $blog = new blog_entry();
        $blog->subject = "comment"; // Normal entry.
        $blog->userid = $user->id;
        $states = blog_entry::get_applicable_publish_states();
        $blog->publishstate = reset($states);
        $blog->add();

        $flags = new stdClass();
        $flags->searchblogs = true;

        $spamusers = $spamcleaner->search_spammers($flags, $keywords, 0, time() + 1000, true);
        $this->assertEquals(1, count($spamusers));
        $spam = array_pop($spamusers);
        $this->assertEquals($user->id, $spam['user']->id);
        $this->assertEquals(1, $spam['spamcount']);
        $spamtext = array_pop($spam['spamtext']);
        $this->assertSame(array('blogpost', "comment poker", "$spamblogid"), $spamtext);

        // Test blog summary.
        $blog = new blog_entry();
        $blog->subject = "something";
        $blog->summary = "comment poker summary"; // Spam entry.
        $blog->userid = $user->id;
        $states = blog_entry::get_applicable_publish_states();
        $blog->publishstate = reset($states);
        $blog->add();
        $spamblogid = $blog->id;

        $blog = new blog_entry();
        $blog->subject = "something";
        $blog->summary = "comment"; // Normal entry.
        $blog->userid = $user->id;
        $states = blog_entry::get_applicable_publish_states();
        $blog->publishstate = reset($states);
        $blog->add();

        $flags = new stdClass();
        $flags->searchblogs = true;

        $spamusers = $spamcleaner->search_spammers($flags, $keywords, 0, time() + 1000, true);
        $this->assertEquals(1, count($spamusers));
        $spam = array_pop($spamusers);
        $this->assertEquals($user->id, $spam['user']->id);
//        $this->assertEquals(2, $spam['spamcount']);
        $spamtext = reset($spam['spamtext']);
        $this->assertSame(array('blogsummary', "comment poker summary", "$spamblogid"), $spamtext);
    }
}
