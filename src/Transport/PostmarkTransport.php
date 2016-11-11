<?php

namespace Diamond\Mail\Transport;


use Swift_Mime_Message;
use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;

class PostmarkTransport extends Transport
{
    /**
     * Guzzle client instance.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * The Postmark Server Token key.
     *
     * @var string
     */
    protected $key;

    /**
     * Create a new Postmark transport instance.
     *
     * @param  \GuzzleHttp\ClientInterface  $client
     * @param  string  $key
     */
    public function __construct(ClientInterface $client, $key)
    {
        $this->key = $key;
        $this->client = $client;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        $options = [
            'json' => $this->getPayload($message),
            'headers' => [
                'X-Postmark-Server-Token' => $this->key
            ]
        ];

        $this->client->post('https://api.postmarkapp.com/email', $options);

        return $this->numberOfRecipients($message);
    }

    /**
     * Convert email dictionary with emails and names
     * to array of emails with names.
     *
     * @param  array  $emails
     * @return array
     */
    private function convertEmailsArray(array $emails) {
        $convertedEmails = array();
        foreach ($emails as $email => $name) {
            $convertedEmails[] = $name
                ? '"' . str_replace('"', '\\"', $name) . "\" <{$email}>"
                : $email;
        }
        return $convertedEmails;
    }

    /**
     * Gets MIME parts that match the message type.
     * Excludes parts of type \Swift_Mime_Attachment as those
     * are handled later.
     *
     * @param  Swift_Mime_Message  $message
     * @param  string              $mimeType
     * @return \Swift_Mime_MimeEntity
     */
    private function getMIMEPart(\Swift_Mime_Message $message, $mimeType) {
        foreach ($message->getChildren() as $part) {
            if (strpos($part->getContentType(), $mimeType) === 0 && !($part instanceof \Swift_Mime_Attachment)) {
                return $part;
            }
        }
    }

    /**
     * Convert a Swift Mime Message to a Postmark Payload.
     *
     * @param  Swift_Mime_Message  $message
     * @return array
     */
    protected function getPayload(Swift_Mime_Message $message) {
        $payload = [];

        $payload['From'] = join(',', $this->convertEmailsArray($message->getFrom()));
        $payload['To'] = join(',', $this->convertEmailsArray($message->getTo()));
        $payload['Subject'] = $message->getSubject();

        if ($cc = $message->getCc()) {
            $payload['Cc'] = join(',', $this->convertEmailsArray($cc));
        }
        if ($reply_to = $message->getReplyTo()) {
            $payload['ReplyTo'] = join(',', $this->convertEmailsArray($reply_to));
        }
        if ($bcc = $message->getBcc()) {
            $payload['Bcc'] = join(',', $this->convertEmailsArray($bcc));
        }

        //Get the primary message.
        switch ($message->getContentType()) {
            case 'text/html':
            case 'multipart/alternative':
            case 'multipart/mixed':
                $payload['HtmlBody'] = $message->getBody();
                break;
            default:
                $payload['TextBody'] = $message->getBody();
                break;
        }

        // Provide an alternate view from the secondary parts.
        if ($plain = $this->getMIMEPart($message, 'text/plain')) {
            $payload['TextBody'] = $plain->getBody();
        }
        if ($html = $this->getMIMEPart($message, 'text/html')) {
            $payload['HtmlBody'] = $html->getBody();
        }
        if ($message->getChildren()) {
            $payload['Attachments'] = array();
            foreach ($message->getChildren() as $attachment) {
                if (is_object($attachment) and $attachment instanceof \Swift_Mime_Attachment) {
                    $a = array(
                        'Name' => $attachment->getFilename(),
                        'Content' => base64_encode($attachment->getBody()),
                        'ContentType' => $attachment->getContentType()
                    );
                    if($attachment->getDisposition() != 'attachment' && $attachment->getId() != NULL) {
                        $a['ContentID'] = 'cid:'.$attachment->getId();
                    }
                    $payload['Attachments'][] = $a;
                }
            }
        }

        if ($message->getHeaders()) {
            $headers = [];

            foreach ($message->getHeaders()->getAll() as $key => $value) {
                $fieldName = $value->getFieldName();

                $excludedHeaders = ['Subject', 'Content-Type', 'MIME-Version', 'Date'];

                if (!in_array($fieldName, $excludedHeaders)) {

                    if ($value instanceof \Swift_Mime_Headers_UnstructuredHeader ||
                        $value instanceof \Swift_Mime_Headers_OpenDKIMHeader) {
                        if($fieldName != 'X-PM-Tag'){
                            array_push($headers, [
                                'Name' => $fieldName,
                                'Value' => $value->getValue(),
                            ]);
                        }else{
                            $payload['Tag'] = $value->getValue();
                        }
                    } else if ($value instanceof \Swift_Mime_Headers_DateHeader ||
                        $value instanceof \Swift_Mime_Headers_IdentificationHeader ||
                        $value instanceof \Swift_Mime_Headers_ParameterizedHeader ||
                        $value instanceof \Swift_Mime_Headers_PathHeader) {
                        array_push($headers, [
                            'Name' => $fieldName,
                            'Value' => $value->getFieldBody(),
                        ]);

                        if ($value->getFieldName() == 'Message-ID') {
                            array_push($headers, [
                                'Name' => 'X-PM-KeepID',
                                'Value' => 'true',
                            ]);
                        }
                    }
                }
            }
            $payload['Headers'] = $headers;
        }

        return $payload;
    }
}