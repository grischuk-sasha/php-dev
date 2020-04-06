<?php

namespace app\Services;

class HelpDesk
{
    public static function createMessage(array $args = [], $isSupport = false, $unique_number = null, $multiFile = false)
    {
        if ( (($multiFile && empty($args['files'])) || (!$multiFile && empty($args['file']))) && empty($args['text']))
            return false;

        if(isset($args['recipient']))
        {
            if($args['recipient'] > 0){
                $args['type'] = 0 ; //$args['recipient'];

                if($args['recipient'] == 1)
                {
                    $args['conversation'] =  $args['chat-client_id'];
                    $args['type'] =  self::CUSTOMER_CONVERSATION;

                    $isIosPush = (!empty($args['isIosPush']) && $args['isIosPush'] == 1);
                    $isAndroidPush = (!empty($args['isAndroidPush']) && $args['isAndroidPush'] == 1);

                    if ($isAndroidPush || $isIosPush) {
                        $customerId = $args['chat-client_id'];
                        $customer = Customer::findOne($customerId);
                        $textForPush = Yii::$app->formatter->asText(preg_replace('/\s+/', ' ', trim($args['text'])));
                        $textForPush = preg_replace('/Kind regards.*/i', '', $textForPush);
                        //$text = preg_replace('/Dear .*?,/i', '', $text);
                        if ($customer !== null) {
                            /** @var MobilePushServiceInterface $mobilePushService */
                            $mobilePushService = Yii::$container->get('MobilePushService');

                            $mobilePushService->sendPushToCustomer($customer, $textForPush, $isIosPush, $isAndroidPush);
                        }
                    }
                }
                elseif($args['recipient'] == 2)
                {
                    $args['conversation'] =  $args['chat-writer_id'];
                    $args['type'] =  self::WRITER_CONVERSATION;
                }
                elseif($args['recipient'] == 3)
                {
                    $args['conversation'] =  $args['chat-client_id'];
                    $args['type'] =  self::CUSTOMER_CONVERSATION;
                    $args['fromWriter'] = 1; //!!!! must be writer id
                }

            }else{
                return false;
            }
        }

        if (isset($args["text"]))
            $args["text"] = strip_tags($args["text"], StringHelper::getAllowedTags());

        if (isset($args["subject"]))
            $args["subject"] = strip_tags($args["subject"], StringHelper::getAllowedTags());

        $args['copy_to_email'] = (isset($args['chat-send_copy_to_email'])) ? intval($args['chat-send_copy_to_email']):0;

        $message = new HdMessage;
        $message->setAttributes($args);

        if ($isSupport) {
            $message->isSupport = 1;
            //$message->status = HdMessage::STATUS_SUPPORT_READ;
        } else {
            //$message->status = isset($args['status']) ? $args['status'] : HdMessage::STATUS_NOT_READ;
        }

        if (empty($message->conversation)) {
            $message->conversation = $args['user_id'];
        }

        $message->created_date = date('Y-m-d H:i:s');
        if ($message->save()) {
            $args['message_id'] = $message->id;

            //загрузка неколькиз файлов
            if ($multiFile) {
                if (!empty($args['files'])) {

                    foreach($args['files']['file'] as $key => $file) {
                        $model = new HdFile();
                        $model->message_id = $message->id;
                        $model->link = $file;
                        $model->alias = empty($args['files']['alias'][$key]) ? NULL : $args['files']['alias'][$key];

                        $model->save();

                        if (isset($file['fileLink']) && $file['fileLink'] != '')
                        {
                            $file = HdFile::find()->where(['link' => $args['fileLink']])->one();
                            if ($file) {
                                $oldMessageId = $file->message_id;
                                $file->message_id = $message->id;
                                $file->update();

                                HdMessage::updateAll(
                                    [
                                        'is_deleted' => 1
                                    ],
                                    [
                                        'id' => $oldMessageId
                                    ]
                                );
                            }
                        }
                    }
                }
            } else {
                if (!empty($args['file'])) {
                    $model = new HdFile();
                    $model->message_id = $message->id;
                    $model->link = $args['file'];
                    $model->alias = empty($args['alias']) ? NULL : $args['alias'];

                    $model->save();
                }

                if (isset($args['fileLink']) && $args['fileLink'] != '') {
                    $file = HdFile::find()->where(['link' => $args['fileLink']])->one();
                    if ($file) {
                        $oldMessageId = $file->message_id;
                        $file->message_id = $message->id;
                        $file->update();

                        HdMessage::updateAll(
                            [
                                'is_deleted' => 1
                            ],
                            [
                                'id' => $oldMessageId
                            ]
                        );
                    }
                }
            }

            $mail = new SentEmailFormChat();
            $mail->execute($args);

            return true;
        }

        return false;
    }
}
