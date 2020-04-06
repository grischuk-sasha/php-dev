<?php

namespace app\modules\Something\controllers;

class SomethingController extends BaseController

public function actionConfirm(WriterSalaryCalculatorBuilderInterface $calculatorBuilder)
    {
        $transaction = Yii::$app->getDb()->beginTransaction();
        try {
            $order  = Order::findOne([
                    'unique_number' => Yii::$app->request->post('unique_number', null),
                    'writer_id'=>0
            ]);

            if (empty($order))
                return ['status' => false];

            $applicant = Applicant::findOne([
                'user_id'  => Yii::$app->user->identity->id,
                'order_id' => $order->id,
                'status' => [Applicant::STATUS_ADD_SUPPORT, Applicant::STATUS_NO_ANSWER_CONFIRM]
            ]);

            if (empty($applicant))
                return [
                    'status' => false
                ];

            /** @var WriterRepositoryInterface $writerRepository */
            $writerRepository = Yii::$container->get(WriterRepositoryInterface::class);
            $writer = $writerRepository->findWriter(Yii::$app->user->identity->id);

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
                $writerOrderFactory = Yii::$container->get(WriterOrderFactory::class);
                $writerOrderFactory->create(new WriterOrderCreateContext([
                    'user_id'  => $writer->id,
                    'order_id' => $order->id,
                    'status'   => WriterOrderStatusEnum::STATUS_UNPAID,
                    'cpp'      => $calculator->calculateWriterCPP($writer, $order),
                ]));
            }

            $result = $order->save();

            if ($result)
                Yii::$app->daddySync->updateInProcessOrderStatus($order);

            $transaction->commit();

            // Create new Order because in Order::getWriter() method return ActiveRecord not an ActiveQuery class as in
            // Order::class
            $Order = new Order($order->getAttributes());
            Yii::$app->getEventDispatcher()->dispatch(new OrderWriterAssignEvent($Order));

        } catch(\Exception $e) {
            $transaction->rollBack();

            Yii::logException($e, __METHOD__);

            $result = false;
        }

        return [
            'status' => $result
        ];
    }
