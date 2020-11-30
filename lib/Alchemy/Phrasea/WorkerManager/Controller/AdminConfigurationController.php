<?php

namespace Alchemy\Phrasea\WorkerManager\Controller;

use Alchemy\Phrasea\Application as PhraseaApplication;
use Alchemy\Phrasea\Controller\Controller;
use Alchemy\Phrasea\Core\Configuration\PropertyAccess;
use Alchemy\Phrasea\Model\Entities\WorkerRunningJob;
use Alchemy\Phrasea\Model\Repositories\WorkerRunningJobRepository;
use Alchemy\Phrasea\SearchEngine\Elastic\ElasticsearchOptions;
use Alchemy\Phrasea\WorkerManager\Event\PopulateIndexEvent;
use Alchemy\Phrasea\WorkerManager\Event\WorkerEvents;
use Alchemy\Phrasea\WorkerManager\Form\WorkerConfigurationType;
use Alchemy\Phrasea\WorkerManager\Form\WorkerFtpType;
use Alchemy\Phrasea\WorkerManager\Form\WorkerPullAssetsType;
use Alchemy\Phrasea\WorkerManager\Form\WorkerSearchengineType;
use Alchemy\Phrasea\WorkerManager\Form\WorkerValidationReminderType;
use Alchemy\Phrasea\WorkerManager\Queue\AMQPConnection;
use Alchemy\Phrasea\WorkerManager\Queue\MessagePublisher;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;


class AdminConfigurationController extends Controller
{
    public function indexAction(PhraseaApplication $app)
    {
        /** @var WorkerRunningJobRepository $repoWorker */
        $repoWorker = $app['repo.worker-running-job'];

        return $this->render('admin/worker-manager/index.html.twig', [
            'isConnected'       => ($this->getAMQPConnection()->getChannel() != null) ? true : false,
            'workerRunningJob'  => $repoWorker->findAll(),
        ]);
    }

    /**
     * @param PhraseaApplication $app
     * @param Request $request
     * @return mixed
     */
    public function configurationAction(PhraseaApplication $app, Request $request)
    {
        $retryQueueConfig = $this->getQueuesConfiguration();

        $AMQPConnection = $this->getAMQPConnection();
        $form = $app->form(new WorkerConfigurationType($AMQPConnection), $retryQueueConfig);

        $form->handleRequest($request);

        if ($form->isValid()) {
            // save config in file
            $app['conf']->set(['workers', 'queues'], $form->getData());

            /*
             * todo : reinitialize q can't depend on form content :
             * e.g. if a ttl_retry is blank in form, the value should go back to default, so the q should be reinit.
             *
            $queues = array_intersect_key(AMQPConnection::$defaultQueues, $retryQueueConfig);
            $retryQueuesToReset = array_intersect_key(AMQPConnection::$defaultRetryQueues, array_flip($queues));

            // change the queue TTL
            $AMQPConnection->reinitializeQueue($retryQueuesToReset);
            $AMQPConnection->reinitializeQueue(AMQPConnection::$defaultDelayedQueues);
            */
            return $app->redirectPath('worker_admin');
        }

        return $this->render('admin/worker-manager/worker_configuration.html.twig', [
            'form' => $form->createView()
        ]);
    }

    public function infoAction(PhraseaApplication $app, Request $request)
    {
        /** @var WorkerRunningJobRepository $repoWorker */
        $repoWorker = $app['repo.worker-running-job'];

        $reload = ($request->query->get('reload') == 1);

        $workerRunningJob = [];
        $filterStatus = [];
        if ($request->query->get('running') == 1) {
            $filterStatus[] = WorkerRunningJob::RUNNING;
        }
        if ($request->query->get('finished') == 1) {
            $filterStatus[] = WorkerRunningJob::FINISHED;
        }
        if ($request->query->get('error') == 1) {
            $filterStatus[] = WorkerRunningJob::ERROR;
        }
        if ($request->query->get('interrupt') == 1) {
            $filterStatus[] = WorkerRunningJob::INTERRUPT;
        }

        if (count($filterStatus) > 0) {
            $workerRunningJob = $repoWorker->findByStatus($filterStatus);
        }

        return $this->render('admin/worker-manager/worker_info.html.twig', [
            'workerRunningJob' => $workerRunningJob,
            'reload'           => $reload
        ]);
    }

    /**
     * @param Request $request
     * @param $workerId
     * @return JsonResponse
     * @throws OptimisticLockException
     */
    public function changeStatusAction(Request $request, $workerId)
    {
        /** @var WorkerRunningJobRepository $repoWorker */
        $repoWorker = $this->app['repo.worker-running-job'];

        /** @var WorkerRunningJob $workerRunningJob */
        $workerRunningJob = $repoWorker->find($workerId);

        $workerRunningJob
            ->setStatus($request->request->get('status'))
            ->setFinished(new \DateTime('now'))
        ;

        $em = $repoWorker->getEntityManager();
        $em->persist($workerRunningJob);

        $em->flush();

        return $this->app->json(['success' => true]);
    }

    public function queueMonitorAction(PhraseaApplication $app, Request $request)
    {
        $reload = ($request->query->get('reload') == 1);

        $this->getAMQPConnection()->getChannel();
        $this->getAMQPConnection()->declareExchange();
        $queuesStatus = $this->getAMQPConnection()->getQueuesStatus();

        return $this->render('admin/worker-manager/worker_queue_monitor.html.twig', [
            'queuesStatus' => $queuesStatus,
            'reload'       => $reload
        ]);
    }

    public function purgeQueueAction(PhraseaApplication $app, Request $request)
    {
        $queueName = $request->request->get('queueName');

        if (empty($queueName)) {
            return $this->app->json(['success' => false]);
        }

        $this->getAMQPConnection()->reinitializeQueue([$queueName]);

        return $this->app->json(['success' => true]);
    }

    public function truncateTableAction(PhraseaApplication $app)
    {
        /** @var WorkerRunningJobRepository $repoWorker */
        $repoWorker = $app['repo.worker-running-job'];
        $repoWorker->truncateWorkerTable();

        return $app->redirectPath('worker_admin');
    }

    public function deleteFinishedAction(PhraseaApplication $app)
    {
        /** @var WorkerRunningJobRepository $repoWorker */
        $repoWorker = $app['repo.worker-running-job'];
        $repoWorker->deleteFinishedWorks();

        return $app->redirectPath('worker_admin');
    }

    public function searchengineAction(PhraseaApplication $app, Request $request)
    {
        $options = $this->getElasticsearchOptions();

        $form = $app->form(new WorkerSearchengineType(), $options);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $populateInfo = $this->getData($form);

            $this->getDispatcher()->dispatch(WorkerEvents::POPULATE_INDEX, new PopulateIndexEvent($populateInfo));

            return $app->redirectPath('worker_admin');
        }

        return $this->render('admin/worker-manager/worker_searchengine.html.twig', [
            'form' => $form->createView()
        ]);
    }

    public function subviewAction()
    {
        return $this->render('admin/worker-manager/worker_subview.html.twig', [
        ]);
    }

    public function metadataAction()
    {
        return $this->render('admin/worker-manager/worker_metadata.html.twig', [
        ]);
    }

    public function ftpAction(PhraseaApplication $app, Request $request)
    {
        $ftpConfig = $this->getFtpConfiguration();
        $form = $app->form(new WorkerFtpType(), $ftpConfig);

        $form->handleRequest($request);
        if ($form->isValid()) {
            // save new ftp config
            $app['conf']->set(['workers', 'ftp'], array_merge($ftpConfig, $form->getData()));

            return $app->redirectPath('worker_admin');
        }

        return $this->render('admin/worker-manager/worker_ftp.html.twig', [
            'form' => $form->createView()
        ]);
    }

    public function validationReminderAction(PhraseaApplication $app, Request $request)
    {
        // nb : the "interval" for a loop-q is the ttl.
        // so the setting is stored into the "queues" settings in conf.
        // here only the "ttl_retry" can be set/changed in conf
        $config = $this->getConf()->get(['workers', 'queues', MessagePublisher::VALIDATION_REMINDER_TYPE], []);
        if(isset($config['ttl_retry'])) {
            // all settings are in msec, but into the form we want large numbers in sec.
            $config['ttl_retry'] /= 1000;
        }
        /** @var Form $form */
        $form = $app->form(new WorkerValidationReminderType($this->getAMQPConnection()), $config);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            switch($data['act']) {
                case 'save' :   // save the form content (settings)
                    unset($data['act']);    // don't save this
                    // the interval was displayed in sec. in form, convert back to msec
                    if(isset($data['ttl_retry'])) {
                        $data['ttl_retry'] *= 1000;
                    }
                    $app['conf']->set(['workers', 'queues', MessagePublisher::VALIDATION_REMINDER_TYPE], $data);
                    break;
                case 'start':
                    // reinitialize the validation reminder queues
                    $this->getAMQPConnection()->setQueue(MessagePublisher::VALIDATION_REMINDER_TYPE);
                    $this->getAMQPConnection()->reinitializeQueue([MessagePublisher::VALIDATION_REMINDER_TYPE]);
                    $this->getMessagePublisher()->initializeLoopQueue(MessagePublisher::VALIDATION_REMINDER_TYPE);
                    break;
                case 'stop':
                    $this->getAMQPConnection()->reinitializeQueue([MessagePublisher::VALIDATION_REMINDER_TYPE]);
                    break;
            }


            return $app->redirectPath('worker_admin');
        }

        return $this->render('admin/worker-manager/worker_validation_reminder.html.twig', [
            'form' => $form->createView()
        ]);
    }

    public function populateStatusAction(PhraseaApplication $app, Request $request)
    {
        $databoxIds = $request->get('sbasIds');

        /** @var WorkerRunningJobRepository $repoWorkerJob */
        $repoWorkerJob = $app['repo.worker-running-job'];

        return $repoWorkerJob->checkPopulateStatusByDataboxIds($databoxIds);
    }

    public function pullAssetsAction(PhraseaApplication $app, Request $request)
    {
        $pullAssetsConfig = $this->getPullAssetsConfiguration();
        $form = $app->form(new WorkerPullAssetsType(), $pullAssetsConfig);

        $form->handleRequest($request);
        if ($form->isValid()) {

            // save new pull config
            $app['conf']->set(['workers', 'pull_assets'], array_merge($pullAssetsConfig, $form->getData()));

            $this->getAMQPConnection()->setQueue(MessagePublisher::PULL_ASSETS_TYPE);

            // reinitialize the pull queues
            $this->getAMQPConnection()->reinitializeQueue([MessagePublisher::PULL_ASSETS_TYPE]);
            $this->getMessagePublisher()->initializeLoopQueue(MessagePublisher::PULL_ASSETS_TYPE);

            return $app->redirectPath('worker_admin');
        }

        return $this->render('admin/worker-manager/worker_pull_assets.html.twig', [
            'form' => $form->createView()
        ]);
    }



    /**
     * @return MessagePublisher
     */
    private function getMessagePublisher()
    {
        return $this->app['alchemy_worker.message.publisher'];
    }

    /**
     * @return EventDispatcherInterface
     */
    private function getDispatcher()
    {
        return $this->app['dispatcher'];
    }

    /**
     * @return ElasticsearchOptions
     */
    private function getElasticsearchOptions()
    {
        return $this->app['elasticsearch.options'];
    }

    /**
     * @param FormInterface $form
     * @return array
     */
    private function getData(FormInterface $form)
    {
        /** @var ElasticsearchOptions $options */
        $options = $form->getData();

        $data['host'] = $options->getHost();
        $data['port'] = $options->getPort();
        $data['indexName'] = $options->getIndexName();
        $data['databoxIds'] = $form->getExtraData()['sbas'];

        return $data;
    }

    private function getPullAssetsConfiguration()
    {
        return $this->getConf()->get(['workers', 'pull_assets'], []);
    }

    private function getFtpConfiguration()
    {
        return $this->getConf()->get(['workers', 'ftp'], []);
    }

    private function getQueuesConfiguration()
    {
        return $this->getConf()->get(['workers', 'queues'], []);
    }

    /**
     * @return AMQPConnection
     */
    private function getAMQPConnection()
    {
        return $this->app['alchemy_worker.amqp.connection'];
    }

}
