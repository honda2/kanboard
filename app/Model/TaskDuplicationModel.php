<?php

namespace Kanboard\Model;

use DateTime;
use DateInterval;
use Kanboard\Core\Base;
use Kanboard\Event\TaskEvent;

/**
 * Task Duplication
 *
 * @package  Kanboard\Model
 * @author   Frederic Guillot
 */
class TaskDuplicationModel extends Base
{
    /**
     * Fields to copy when duplicating a task
     *
     * @access private
     * @var array
     */
    private $fields_to_duplicate = array(
        'title',
        'description',
        'date_due',
        'color_id',
        'project_id',
        'column_id',
        'owner_id',
        'score',
        'category_id',
        'time_estimated',
        'swimlane_id',
        'recurrence_status',
        'recurrence_trigger',
        'recurrence_factor',
        'recurrence_timeframe',
        'recurrence_basedate',
    );

    /**
     * Duplicate a task to the same project
     *
     * @access public
     * @param  integer             $task_id      Task id
     * @return boolean|integer                   Duplicated task id
     */
    public function duplicate($task_id)
    {
        return $this->save($task_id, $this->copyFields($task_id));
    }

    /**
     * Duplicate recurring task
     *
     * @access public
     * @param  integer             $task_id      Task id
     * @return boolean|integer                   Recurrence task id
     */
    public function duplicateRecurringTask($task_id)
    {
        $values = $this->copyFields($task_id);

        if ($values['recurrence_status'] == TaskModel::RECURRING_STATUS_PENDING) {
            $values['recurrence_parent'] = $task_id;
            $values['column_id'] = $this->columnModel->getFirstColumnId($values['project_id']);
            $this->calculateRecurringTaskDueDate($values);

            $recurring_task_id = $this->save($task_id, $values);

            if ($recurring_task_id > 0) {
                $parent_update = $this->db
                    ->table(TaskModel::TABLE)
                    ->eq('id', $task_id)
                    ->update(array(
                        'recurrence_status' => TaskModel::RECURRING_STATUS_PROCESSED,
                        'recurrence_child' => $recurring_task_id,
                    ));

                if ($parent_update) {
                    return $recurring_task_id;
                }
            }
        }

        return false;
    }

    /**
     * Duplicate a task to another project
     *
     * @access public
     * @param  integer    $task_id
     * @param  integer    $project_id
     * @param  integer    $swimlane_id
     * @param  integer    $column_id
     * @param  integer    $category_id
     * @param  integer    $owner_id
     * @return boolean|integer
     */
    public function duplicateToProject($task_id, $project_id, $swimlane_id = null, $column_id = null, $category_id = null, $owner_id = null)
    {
        $values = $this->copyFields($task_id);
        $values['project_id'] = $project_id;
        $values['column_id'] = $column_id !== null ? $column_id : $values['column_id'];
        $values['swimlane_id'] = $swimlane_id !== null ? $swimlane_id : $values['swimlane_id'];
        $values['category_id'] = $category_id !== null ? $category_id : $values['category_id'];
        $values['owner_id'] = $owner_id !== null ? $owner_id : $values['owner_id'];

        $this->checkDestinationProjectValues($values);

        return $this->save($task_id, $values);
    }

    /**
     * Move a task to another project
     *
     * @access public
     * @param  integer    $task_id
     * @param  integer    $project_id
     * @param  integer    $swimlane_id
     * @param  integer    $column_id
     * @param  integer    $category_id
     * @param  integer    $owner_id
     * @return boolean
     */
    public function moveToProject($task_id, $project_id, $swimlane_id = null, $column_id = null, $category_id = null, $owner_id = null)
    {
        $task = $this->taskFinderModel->getById($task_id);

        $values = array();
        $values['is_active'] = 1;
        $values['project_id'] = $project_id;
        $values['column_id'] = $column_id !== null ? $column_id : $task['column_id'];
        $values['position'] = $this->taskFinderModel->countByColumnId($project_id, $values['column_id']) + 1;
        $values['swimlane_id'] = $swimlane_id !== null ? $swimlane_id : $task['swimlane_id'];
        $values['category_id'] = $category_id !== null ? $category_id : $task['category_id'];
        $values['owner_id'] = $owner_id !== null ? $owner_id : $task['owner_id'];

        $this->checkDestinationProjectValues($values);

        if ($this->db->table(TaskModel::TABLE)->eq('id', $task['id'])->update($values)) {
            $this->container['dispatcher']->dispatch(
                TaskModel::EVENT_MOVE_PROJECT,
                new TaskEvent(array_merge($task, $values, array('task_id' => $task['id'])))
            );
        }

        return true;
    }

    /**
     * Check if the assignee and the category are available in the destination project
     *
     * @access public
     * @param  array      $values
     * @return array
     */
    public function checkDestinationProjectValues(array &$values)
    {
        // Check if the assigned user is allowed for the destination project
        if ($values['owner_id'] > 0 && ! $this->projectPermissionModel->isUserAllowed($values['project_id'], $values['owner_id'])) {
            $values['owner_id'] = 0;
        }

        // Check if the category exists for the destination project
        if ($values['category_id'] > 0) {
            $values['category_id'] = $this->categoryModel->getIdByName(
                $values['project_id'],
                $this->categoryModel->getNameById($values['category_id'])
            );
        }

        // Check if the swimlane exists for the destination project
        if ($values['swimlane_id'] > 0) {
            $values['swimlane_id'] = $this->swimlaneModel->getIdByName(
                $values['project_id'],
                $this->swimlaneModel->getNameById($values['swimlane_id'])
            );
        }

        // Check if the column exists for the destination project
        if ($values['column_id'] > 0) {
            $values['column_id'] = $this->columnModel->getColumnIdByTitle(
                $values['project_id'],
                $this->columnModel->getColumnTitleById($values['column_id'])
            );

            $values['column_id'] = $values['column_id'] ?: $this->columnModel->getFirstColumnId($values['project_id']);
        }

        return $values;
    }

    /**
     * Calculate new due date for new recurrence task
     *
     * @access public
     * @param  array   $values   Task fields
     */
    public function calculateRecurringTaskDueDate(array &$values)
    {
        if (! empty($values['date_due']) && $values['recurrence_factor'] != 0) {
            if ($values['recurrence_basedate'] == TaskModel::RECURRING_BASEDATE_TRIGGERDATE) {
                $values['date_due'] = time();
            }

            $factor = abs($values['recurrence_factor']);
            $subtract = $values['recurrence_factor'] < 0;

            switch ($values['recurrence_timeframe']) {
                case TaskModel::RECURRING_TIMEFRAME_MONTHS:
                    $interval = 'P' . $factor . 'M';
                    break;
                case TaskModel::RECURRING_TIMEFRAME_YEARS:
                    $interval = 'P' . $factor . 'Y';
                    break;
                default:
                    $interval = 'P' . $factor . 'D';
            }

            $date_due = new DateTime();
            $date_due->setTimestamp($values['date_due']);

            $subtract ? $date_due->sub(new DateInterval($interval)) : $date_due->add(new DateInterval($interval));

            $values['date_due'] = $date_due->getTimestamp();
        }
    }

    /**
     * Duplicate fields for the new task
     *
     * @access private
     * @param  integer       $task_id      Task id
     * @return array
     */
    private function copyFields($task_id)
    {
        $task = $this->taskFinderModel->getById($task_id);
        $values = array();

        foreach ($this->fields_to_duplicate as $field) {
            $values[$field] = $task[$field];
        }

        return $values;
    }

    /**
     * Create the new task and duplicate subtasks
     *
     * @access private
     * @param  integer            $task_id      Task id
     * @param  array              $values       Form values
     * @return boolean|integer
     */
    private function save($task_id, array $values)
    {
        $new_task_id = $this->taskCreationModel->create($values);

        if ($new_task_id) {
            $this->subtaskModel->duplicate($task_id, $new_task_id);
        }

        return $new_task_id;
    }
}
