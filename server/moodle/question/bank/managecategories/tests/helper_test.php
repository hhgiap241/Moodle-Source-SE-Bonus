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

namespace qbank_managecategories;

/**
 * Unit tests for helper class.
 *
 * @package    qbank_managecategories
 * @copyright  2006 The Open University
 * @author     2021, Guillermo Gomez Arias <guillermogomez@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \qbank_managecategories\helper
 */
class helper_test extends \advanced_testcase {

    /**
     * @var \context_module module context.
     */
    protected $context;

    /**
     * @var \stdClass course object.
     */
    protected $course;

    /**
     * @var \component_generator_base question generator.
     */
    protected $qgenerator;

    /**
     * @var \stdClass quiz object.
     */
    protected $quiz;

    /**
     * Tests initial setup.
     */
    protected function setUp(): void {
        parent::setUp();
        self::setAdminUser();
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $this->course = $datagenerator->create_course();
        $this->quiz = $datagenerator->create_module('quiz', ['course' => $this->course->id]);
        $this->qgenerator = $datagenerator->get_plugin_generator('core_question');
        $this->context = \context_module::instance($this->quiz->cmid);
    }

    /**
     * Test question_remove_stale_questions_from_category function.
     *
     * @covers ::question_remove_stale_questions_from_category
     */
    public function test_question_remove_stale_questions_from_category() {
        global $DB;

        $qcat1 = $this->qgenerator->create_question_category(['contextid' => $this->context->id]);
        $q1a = $this->qgenerator->create_question('shortanswer', null, ['category' => $qcat1->id]);     // Will be hidden.
        $DB->set_field('question', 'hidden', 1, ['id' => $q1a->id]);

        $qcat2 = $this->qgenerator->create_question_category(['contextid' => $this->context->id]);
        $q2a = $this->qgenerator->create_question('shortanswer', null, ['category' => $qcat2->id]);     // Will be hidden.
        $q2b = $this->qgenerator->create_question('shortanswer', null, ['category' => $qcat2->id]);     // Will be hidden but used.
        $DB->set_field('question', 'hidden', 1, ['id' => $q2a->id]);
        $DB->set_field('question', 'hidden', 1, ['id' => $q2b->id]);
        quiz_add_quiz_question($q2b->id, $this->quiz);
        quiz_add_random_questions($this->quiz, 0, $qcat2->id, 1, false);

        // We added one random question to the quiz and we expect the quiz to have only one random question.
        $q2d = $DB->get_record_sql("SELECT q.*
                                      FROM {question} q
                                      JOIN {quiz_slots} s ON s.questionid = q.id
                                     WHERE q.qtype = :qtype
                                           AND s.quizid = :quizid",
            ['qtype' => 'random', 'quizid' => $this->quiz->id], MUST_EXIST);

        // The following 2 lines have to be after the quiz_add_random_questions() call above.
        // Otherwise, quiz_add_random_questions() will to be "smart" and use them instead of creating a new "random" question.
        $q1b = $this->qgenerator->create_question('random', null, ['category' => $qcat1->id]);          // Will not be used.
        $q2c = $this->qgenerator->create_question('random', null, ['category' => $qcat2->id]);          // Will not be used.

        $this->assertEquals(2, $DB->count_records('question', ['category' => $qcat1->id]));
        $this->assertEquals(4, $DB->count_records('question', ['category' => $qcat2->id]));

        // Non-existing category, nothing will happen.
        helper::question_remove_stale_questions_from_category(0);
        $this->assertEquals(2, $DB->count_records('question', ['category' => $qcat1->id]));
        $this->assertEquals(4, $DB->count_records('question', ['category' => $qcat2->id]));

        // First category, should be empty afterwards.
        helper::question_remove_stale_questions_from_category($qcat1->id);
        $this->assertEquals(0, $DB->count_records('question', ['category' => $qcat1->id]));
        $this->assertEquals(4, $DB->count_records('question', ['category' => $qcat2->id]));
        $this->assertFalse($DB->record_exists('question', ['id' => $q1a->id]));
        $this->assertFalse($DB->record_exists('question', ['id' => $q1b->id]));

        // Second category, used questions should be left untouched.
        helper::question_remove_stale_questions_from_category($qcat2->id);
        $this->assertEquals(0, $DB->count_records('question', ['category' => $qcat1->id]));
        $this->assertEquals(2, $DB->count_records('question', ['category' => $qcat2->id]));
        $this->assertFalse($DB->record_exists('question', ['id' => $q2a->id]));
        $this->assertTrue($DB->record_exists('question', ['id' => $q2b->id]));
        $this->assertFalse($DB->record_exists('question', ['id' => $q2c->id]));
        $this->assertTrue($DB->record_exists('question', ['id' => $q2d->id]));
    }

    /**
     * Test delete top category in function question_can_delete_cat.
     *
     * @covers ::question_can_delete_cat
     * @covers ::question_is_top_category
     */
    public function test_question_can_delete_cat_top_category() {

        $qcategory1 = $this->qgenerator->create_question_category(['contextid' => $this->context->id]);

        // Try to delete a top category.
        $categorytop = question_get_top_category($qcategory1->id, true)->id;
        $this->expectException('moodle_exception');
        $this->expectExceptionMessage(get_string('cannotdeletetopcat', 'question'));
        helper::question_can_delete_cat($categorytop);
    }

    /**
     * Test delete only child category in function question_can_delete_cat.
     *
     * @covers ::question_can_delete_cat
     * @covers ::question_is_only_child_of_top_category_in_context
     */
    public function test_question_can_delete_cat_child_category() {

        $qcategory1 = $this->qgenerator->create_question_category(['contextid' => $this->context->id]);

        // Try to delete an only child of top category having also at least one child.
        $this->expectException('moodle_exception');
        $this->expectExceptionMessage(get_string('cannotdeletecate', 'question'));
        helper::question_can_delete_cat($qcategory1->id);
    }

    /**
     * Test delete category in function question_can_delete_cat without capabilities.
     *
     * @covers ::question_can_delete_cat
     */
    public function test_question_can_delete_cat_capability() {

        $qcategory1 = $this->qgenerator->create_question_category(['contextid' => $this->context->id]);
        $qcategory2 = $this->qgenerator->create_question_category(['contextid' => $this->context->id, 'parent' => $qcategory1->id]);

        // This call should not throw an exception as admin user has the capabilities moodle/question:managecategory.
        helper::question_can_delete_cat($qcategory2->id);

        // Try to delete a category with and user without the capability.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        $this->expectExceptionMessage(get_string('nopermissions', 'error', get_string('question:managecategory', 'role')));
        helper::question_can_delete_cat($qcategory2->id);
    }

    /**
     * Test question_category_select_menu function.
     *
     * @covers ::question_category_select_menu
     * @covers ::question_category_options
     */
    public function test_question_category_select_menu() {

        $this->qgenerator->create_question_category(['contextid' => $this->context->id, 'name' => 'Test this question category']);
        $contexts = new \question_edit_contexts($this->context);

        ob_start();
        helper::question_category_select_menu($contexts->having_cap('moodle/question:add'));
        $output = ob_get_clean();

        // Test the select menu of question categories output.
        $this->assertStringContainsString('Question category', $output);
        $this->assertStringContainsString('Test this question category', $output);
    }

    /**
     * Test that question_category_options function returns the correct category tree.
     *
     * @covers ::question_category_options
     * @covers ::get_categories_for_contexts
     * @covers ::question_fix_top_names
     * @covers ::question_add_context_in_key
     * @covers ::add_indented_names
     */
    public function test_question_category_options() {

        $qcategory1 = $this->qgenerator->create_question_category(['contextid' => $this->context->id]);
        $qcategory2 = $this->qgenerator->create_question_category(['contextid' => $this->context->id, 'parent' => $qcategory1->id]);
        $qcategory3 = $this->qgenerator->create_question_category(['contextid' => $this->context->id]);

        $contexts = new \question_edit_contexts($this->context);

        // Validate that we have the array with the categories tree.
        $categorycontexts = helper::question_category_options($contexts->having_cap('moodle/question:add'));
        foreach ($categorycontexts as $categorycontext) {
            $this->assertCount(3, $categorycontext);
        }

        // Validate that we have the array with the categories tree and that top category is there.
        $categorycontexts = helper::question_category_options($contexts->having_cap('moodle/question:add'), true);
        foreach ($categorycontexts as $categorycontext) {
            $this->assertCount(4, $categorycontext);
        }
    }
}
