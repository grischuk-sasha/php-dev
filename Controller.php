<?php

namespace app\modules\Something\controllers;

class SomethingController extends BaseController
{

    public function actionConfirm(WriterSalaryCalculatorBuilderInterface $calculatorBuilder)
    {
        $transaction = $this->getDb()->beginTransaction();
        try {
            $order  = Order::findOne([
                    'unique_number' => $this->request->post('unique_number', null),
                    'writer_id'=>0
            ]);

            if (empty($order))
                return ['status' => false];

            $applicant = Applicant::findOne([
                'user_id'  => $this->user->identity->id,
                'order_id' => $order->id,
                'status' => [Applicant::STATUS_ADD_SUPPORT, Applicant::STATUS_NO_ANSWER_CONFIRM]
            ]);

            if (empty($applicant))
                return [
                    'status' => false
                ];

            /** @var WriterRepositoryInterface $writerRepository */
            $writerRepository = $this->container->get(WriterRepositoryInterface::class);
            $writer = $writerRepository->findWriter($this->user->identity->id);

            if (empty($order) || empty($writer)) {
                return ['status' => false];
            }

            $applicant->setAttributes([
                'status'=>Applicant::STATUS_ASSIGN,
                'date_confirm'=>date("Y-m-d H:i:s"),
                'is_declined'=>0,
            ]);
            $applicant->save();

            $order->writer_id = $writer->id;

            $wo = WriterOrder::findOne([
                'order_id' => $order->id,
                'user_id' => $writer->id
            ]);

            if (!empty($wo) && $wo->status == WriterOrderStatusEnum::STATUS_CANCELED)
            {
                $wo->status = WriterOrderStatusEnum::STATUS_UNPAID;
                $wo->save();
            }
            elseif (empty($wo))
            {
                $calculator = $calculatorBuilder->buildForOrder($order);

                /** @var WriterOrderFactoryInterface $writerOrderFactory */
                $writerOrderFactory = $this->container->get(WriterOrderFactory::class);
                $writerOrderFactory->create(new WriterOrderCreateContext([
                    'user_id'  => $writer->id,
                    'order_id' => $order->id,
                    'status'   => WriterOrderStatusEnum::STATUS_UNPAID,
                    'cpp'      => $calculator->calculateWriterCPP($writer, $order),
                ]));
            }

            $result = $order->save();

            if ($result)
                $this->daddySync->updateInProcessOrderStatus($order);

            $transaction->commit();

            // Create new Order because in Order::getWriter() method return ActiveRecord not an ActiveQuery class as in
            // Order::class
            $Order = new Order($order->getAttributes());
            $this->getEventDispatcher()->dispatch(new OrderWriterAssignEvent($Order));

        } catch(\Exception $e) {
            $transaction->rollBack();

            $this->logException($e, __METHOD__);

            $result = false;
        }

        return [
            'status' => $result
        ];
    }
    
    public function actionCreate()
    {
        /* @var $applicantCreateForm ApplicantCreateForm */
        $applicantCreateForm = new ApplicantCreateForm();
        $applicantCreateForm->setAttributes($this->request->getBodyParams());
        $applicantCreateForm->setOrder($this->request->post('order_id', null));
        $applicantCreateForm->setUserId($this->user->identity->id);

        /* @var $order Order */
        $order  = $applicantCreateForm->getOrder();
        /** @var WriterRepositoryInterface $writerRepository */
        $writerRepository = $this->$container->get(WriterRepositoryInterface::class);
        $writer = $writerRepository->findWriter($this->user->identity->id);
        $writerAnotherSiteAccounts = $writerRepository->findWriterOtherSitesAccounts($writer);
        $writerAnotherSiteAccounts = ArrayHelper::getColumn($writerAnotherSiteAccounts, 'id');

        if (!$applicantCreateForm->validate() ||
            empty($writer) ||
            ($order->level > $this->user->identity->level) ||
            Applicant::checkWriterApplied($order->id, $writerAnotherSiteAccounts)
        ) {
            return [
                'status' => false,
                'error' => 'Error while applying to order'
            ];
        }

        $writerStatistic = new WriterStatistic($writer->id);
        if (!($writer->max_orders > $writerStatistic->orderCurrent()))
            return [
                'status' => 3,
                'error' => 'Sorry, you can have maximum ' . $writer->max_orders . ' orders in process. 
                You can apply for new orders when at least one of the current orders is in awaiting feedback section.'
            ];

        $applicantDuplicate = Applicant::findOne([
            'user_id'  => $this->user->identity->id,
            'order_id' => $order->id,
            'status' => Applicant::STATUS_ADD_WRITER
        ]);
        if ($applicantDuplicate !== null) {
            return [
                'status' => 3,
                'error' => 'The application was already submitted to order'
            ];
        }

        if ($order->trusted && $writer->trusted) {
            $order->writer_id = $writer->id;

            $result = $order->save();

            return [
                'status' => $result ? 1 : false,
                'error' => $result ? '' : 'Error while applying to order'
            ];
        }

        try {
            /** @var \app\modules\Applicant\factory\ApplicantFactory\ApplicantFactoryInterface $applicantFactory */
            $applicantFactory = $this->container->get("ApplicantFactory");
            $applicantFactory->create($applicantCreateForm);
        } catch (\Exception $e) {

            $this->logException($e, __METHOD__);

            return [
                'status' =>  false,
                'error' =>  'Error while applying to order',
            ];
        }

        return [
            'status' =>  2,
            'error' =>  ''
        ];
    }
}
