<?php
/**
 * Group-Office
 * 
 * Copyright Intermesh BV. 
 * This file is part of Group-Office. You should have received a copy of the
 * Group-Office license along with Group-Office. See the file /LICENSE.TXT
 *
 * If you have questions write an e-mail to info@intermesh.nl
 * 
 * @license AGPL/Proprietary http://www.group-office.com/LICENSE.TXT
 * @link http://www.group-office.com
 * @copyright Copyright Intermesh BV
 * @version $Id: CronController.php 7962 2011-08-24 14:48:45Z wsmits $
 * @author Wesley Smits <wsmits@intermesh.nl>
 * @package GO.core.controller
 */


namespace GO\Core\Controller;


use DateTimeZone;
use Cron\CronExpression;
use GO\Base\Util\Date;
use go\core\model\CronJobSchedule;
use go\core\util\DateTime;
use function mysql_xdevapi\getSession;

class CronController extends \GO\Base\Controller\AbstractJsonController{

	private $tz;

	protected function allowGuests() {
		return array('run', 'runbyid');
	}
	
	//don't check token in this controller
	protected function checkSecurityToken(){}
	
	/**
	 * Update a Cronjob model
	 * 
	 * @param int $id
	 */
  protected function actionUpdate($id) {
		$model = \GO\Base\Cron\CronJob::model()->findByPk($id);
		
		$remoteComboFields = array();
		
		// Add parameter for checking if the use
		if(!empty($model->job)){
			$cron = new $model->job;
			$select = $cron->enableUserAndGroupSupport();
			$remoteComboFields['job']='"'.$cron->getLabel().'"';
		} else {
			$select = false;
		}
		
		if(\GO\Base\Util\Http::isPostRequest()) {
			$model->setAttributes($_POST);
			$model->save();
			echo $this->renderSubmit($model);
		} else {
			echo $this->renderForm($model, $remoteComboFields,array('select'=>$select, 'paramsToSet' => $model->getParamsToSet()));
		}
		
  }
	
	/**
	 * Create a new Cronjob model
	 */
	protected function actionCreate() {
		
		$model = new \GO\Base\Cron\CronJob();
		
		if(\GO\Base\Util\Http::isPostRequest()) {
			$model->setAttributes($_POST);
			$model->save();
			echo $this->renderSubmit($model);
		}else {
			echo $this->renderForm($model, array(),array('select'=>false, 'paramsToSet' => $model->getParamsToSet()));
		}
  }

	/**
	 * Get a list of all created Cronjob models
	 * 
	 * @param array $params
	 */
	public function actionStore(array $params)
	{
		$colModel = new \GO\Base\Data\ColumnModel(\GO\Base\Cron\CronJob::model());
					
		$colModel->formatColumn('active', function($model){
			if($model->active!=1 && !empty($model->error)) {
				return \GO::t("Error", "cron");
			}
			return $model->isRunning()?\GO::t("Running", "cron"):$model->active;
		});
		$colModel->formatColumn('error', '$model->error');
		$colModel->formatColumn('expression', '$model->_buildExpression()');
		
		$store = new \GO\Base\Data\DbStore('GO\Base\Cron\CronJob',$colModel, $params, \GO\Base\Db\FindParams::newInstance()->select('*'));
		$store->defaultSort = 'name';
		$store->limit = 0;
		
		$response =  $this->renderStore($store);	
		if(!\GO::cronIsRunning()){
			$message = "The main cron job doesn't appear to be running. Please add a cron job: \n\n* * * * * www-data php ".\GO::config()->root_path."cron.php ".\GO::config()->get_config_file();
			$response['feedback']=$message;
			//throw new \GO\Base\Exception\NoCron(); <-- will not load grid
		}

		$response['results'] = array_merge($response['results'], $this->getNewCronJobs());
		
		
		echo $response;
	}

	/**
	 * Get cron jobs from within the new framework
	 *
	 * @return array
	 */
	private function getNewCronJobs(): array
	{
		//['id','name','active','minutes', 'hours','error', 'monthdays', 'months',
		// 'weekdays','years','job','nextrun','lastrun','completedat'],

		$jobs = [];
		foreach(CronJobSchedule::find() as $job) {

			$expression = CronExpression::factory($job->expression);

			$record = [
				'id' => "new:" . $job->id,
				'name' => $job->description,
				'active' => $job->enabled,
				'expression' => $job->expression,
				'minutes' => $expression->getExpression(CronExpression::MINUTE),
				'hours' => $expression->getExpression(CronExpression::HOUR),
				'monthdays' => $expression->getExpression(CronExpression::DAY),
				'months' => $expression->getExpression(CronExpression::MONTH),
				'weekdays' => $expression->getExpression(CronExpression::WEEKDAY),
				'years' =>  $expression->getExpression(CronExpression::YEAR),
				'job' => $job->getCronClass(),
				'nextrun' => $job->nextRunAt ? $this->adjustToUtc($job->nextRunAt) : "-",
				'lastrun' => $job->runningSince ? $this->adjustToUtc($job->runningSince) : ($job->lastRunAt ? $this->adjustToUtc($job->lastRunAt) : "-"),
				'completedat' => $job->lastRunAt ? $this->adjustToUtc($job->lastRunAt) : "-",
				'error' => $job->lastError

			];
			$jobs[] = $record;
		};

		return $jobs;
	}
	
	
	/**
	 * Get a list of all created Cronjob models that have a 'nextrun' between the 
	 * $params['from'] and $params['till'] time.
	 * 
	 * If $params['from'] and $params['till'] are not given then
	 * From = the current time
	 * Till = the current time + 1 day
	 * 
	 * @param array $params
	 */
	public function actionRunBetween(array $params)
	{
		$from = false;
		$till = false;
		
		if(isset($params['from'])) {
			$from = new \GO\Base\Util\Date\DateTime($params['from']);
		}
		
		if(isset($params['till'])) {
			$till = new \GO\Base\Util\Date\DateTime($params['till']);
		}
		
		if(!$from) {
			$from = new \GO\Base\Util\Date\DateTime();
		}
		
		if(!$till){
			$till = new \GO\Base\Util\Date\DateTime();
			$till->add(new \DateInterval('P1D'));
		}
		
		$findParams = \GO\Base\Db\FindParams::newInstance()
			->criteria(\GO\Base\Db\FindCriteria::newInstance()
				->addCondition('nextrun', $till->getTimestamp(),'<')
				->addCondition('nextrun', $from->getTimestamp(),'>')
				->addCondition('active', 1,'=')
			);
		
		$colModel = new \GO\Base\Data\ColumnModel(\GO\Base\Cron\CronJob::model());
		
		$store = new \GO\Base\Data\DbStore('GO\Base\Cron\CronJob',$colModel , $params, $findParams);
		$store->defaultSort = 'nextrun';
		
		$result = $this->renderStore($store);
		
		$result['from'] = $from->format('d-m-Y H:i');
		$result['till'] = $till->format('d-m-Y H:i');
		
		echo $result;
	}
	
	private function _findNextCron(){
		$currentTime = new \GO\Base\Util\Date\DateTime();

		$findParams = \GO\Base\Db\FindParams::newInstance()
			->single()
			->criteria(\GO\Base\Db\FindCriteria::newInstance()
				->addCondition('nextrun', $currentTime->getTimestamp(),'<')
				->addCondition('active',true)
			);
		
		return \GO\Base\Cron\CronJob::model()->find($findParams);
	}

	
	protected function actionRunById($params) {
		$job = \GO\Base\Cron\CronJob::model()->findByPk($params['id']);
		$job->run();
	}

	/**
	 * Get all availabe cron files that are selectable when creating a new cron.
	 * 
	 * @return array
	 */
	protected function actionAvailableCronCollection($params){
		$response = array();
		$response['results'] = array();
		
		$cronJobCollection = new \GO\Base\Cron\CronCollection();
		
		$cronfiles = $cronJobCollection->getAllCronJobClasses();
		$response['total'] = count($cronfiles);
		foreach($cronfiles as $c=>$label){
			
			$cObject = new $c();
			$userAndGroupSelection = $cObject->enableUserAndGroupSupport();
						
			$response['results'][] = array('name'=>$label,'class'=>$c,'selection'=>$userAndGroupSelection);
		}
		
		$response['success'] = true;
		
		return $response;
	}
	
	/**
	 * Load the settings panel
	 * 
	 * @param array $params
	 * @return array
	 */
	protected function actionLoadSettings($params) {
		
		$settings =  \GO\Base\Cron\CronSettings::load();
		
		return array(
				'success'=>true,
				'data'=>$settings->getArray()
		);
	}
	
	/**
	 * Save the settings panel
	 * 
	 * @param array $params
	 * @return array
	 */
	protected function actionSubmitSettings($params) {
		
		$settings =  \GO\Base\Cron\CronSettings::load();

		return array(
				'success'=>$settings->saveFromArray($params),
				'data'=>$settings->getArray()
		);
	}

	/**
	 * Hackish function to 'convert' new FW cronjobs to UTC
	 *
	 * Timestamps are correctly stored and retrieved, but somewhere along the line, the default  timezone is not being
	 * taken into account. For old FW cron jobs this is being done correctly. After some time debugging, still not sure
	 * why old FW timestamps are correctly being displayed.
	 *
	 * @param DateTime $dt
	 * @return int
	 */
	private function adjustToUtc(DateTime $dt): int
	{
		if(!isset($this->tz)) {
			$this->tz = new DateTimeZone(go()->getSettings()->defaultTimezone);
		}
		$t = $dt->getTimestamp();
		$t -= intval($dt->setTimeZone($this->tz)->format('Z'));
		return $t;
	}
	
	
}
