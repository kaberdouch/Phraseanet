<?php
/**
 * This file is part of Phraseanet
 *
 * (c) 2005-2020 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Command;

use Alchemy\Phrasea\Application\Helper\NotifierAware;
use Alchemy\Phrasea\Core\Configuration\PropertyAccess;
use Alchemy\Phrasea\Core\LazyLocator;
use Alchemy\Phrasea\Model\Entities\Basket;
use Alchemy\Phrasea\Model\Entities\User;
use Alchemy\Phrasea\Model\Entities\ValidationParticipant;
use Alchemy\Phrasea\Model\Repositories\TokenRepository;
use Alchemy\Phrasea\Model\Repositories\ValidationParticipantRepository;
use Alchemy\Phrasea\Notification\Emitter;
use Alchemy\Phrasea\Notification\Mail\MailInfoValidationReminder;
use Alchemy\Phrasea\Notification\Receiver;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SendValidationRemindersCommand extends Command
{
    use NotifierAware;

    const DATE_FMT = "Y-m-d H:i:s";

    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /** @var bool */
    private $dry;

    /** @var DateTime */
    private $now;

    private $days;

    public function __construct( /** @noinspection PhpUnusedParameterInspection */ $name = null)
    {
        parent::__construct('validation:remind');

        $this->setDescription('Send validation reminders. <comment>(experimental)</comment>');
        $this->addOption('dry',null, InputOption::VALUE_NONE,'dry run, list but don\'t act');
        $this->addOption('now', null,InputArgument::OPTIONAL, 'fake today');
        $this->addOption('days', null,InputArgument::OPTIONAL, 'overwrite validation-reminder-days');
    }


    /**
     * sanity check the cmd line options
     *
     * @return bool
     */
    protected function sanitizeArgs()
    {
        $r = true;

        // --dry
        $this->dry  = $this->input->getOption('dry') ? true : false;

        // --now
        if(($v = $this->input->getOption('now')) !== null) {
            try {
                $this->now = new DateTime($v);
            }
            catch (Exception $e) {
                $this->output->writeln(sprintf('<error>bad --date "%s"</error>', $v));
                $r = false;
            }
        }
        else {
            $this->now = new DateTime();
        }

        // --days
        if(($v = $this->input->getOption('days')) !== null) {
            if(($this->days = (int)$v) <= 0) {
                $this->output->writeln(sprintf('<error>--days must be > 0 (bad value "%s")</error>', $v));
                $r = false;
            }
        }
        else {
            $this->days = (int)$this->getConf()->get(['registry', 'actions', 'validation-reminder-days']);
        }

        return $r;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;

        $this->setDelivererLocator(new LazyLocator($this->container, 'notification.deliverer'));

        if(!$this->sanitizeArgs()) {
            return -1;
        }

        $date_to = clone($this->now);
        $date_to->add(new \DateInterval(sprintf('P%dD', $this->days)));

        if($this->dry) {
            $this->output->writeln('<info>dry mode : emails will NOT be sent</info>');
        }

        $output->writeln(sprintf('from "%s" to "%s" (days=%d), ', $this->now->format(self::DATE_FMT), $date_to->format(self::DATE_FMT), $this->days));

        $fmt = '   participant: %-11s  user: %-10s %s  token: %-10s ';
        //$output->writeln(sprintf($fmt, 'session', 'basket', 'participant', 'user', 'token', 'email'));

        $last_session = null;
        foreach ($this->getValidationParticipantRepository()->findNotConfirmedAndNotRemindedParticipantsByExpireDate($date_to, $this->now) as $participant) {
            $validationSession = $participant->getSession();
            $basket = $validationSession->getBasket();

            // change session ? display header
            if($validationSession->getId() !== $last_session) {
                try {
                    $basket_name = $basket->getName();
                }
                catch(Exception $e) {
                    // basket not found ?
                    $basket_name = '?';
                }

                try {
                    $initiator_email = $validationSession->getInitiator()->getEmail();
                }
                catch(Exception $e) {
                    // initiator user not found ?
                    $initiator_email = '?';
                }

                $output->writeln('');
                $output->writeln(sprintf('session_id: %s (created %s by "%s", expire %s), basket_id: %s ("%s")',
                    $validationSession->getId(),
                    $validationSession->getCreated()->format(self::DATE_FMT),
                    $initiator_email,
                    $validationSession->getExpires()->format(self::DATE_FMT),
                    $basket->getId(),
                    $basket_name
                ));

                $last_session = $validationSession->getId();
            }

            // now display participant
            $can_send = true;

            // fu..ing doctrine : we can get user id if user does not exists ! we must try to hydrate to get an exception !
            $user = $participant->getUser();        // always ok !
            try {
                $str_email = $user->getEmail();     // force to hydrate
            }
            catch (Exception $e) {
                $str_email = 'user not found';
                $can_send = false;
            }

            $token = null;
            try {
                $token = $this->getTokenRepository()->findValidationToken($basket, $user);
                if($token) {
                    $str_token = sprintf('%s (cre. %s, exp. %s)',
                        $this->dotdot($token->getValue(), 10),
                        $token->getCreated()->format(self::DATE_FMT),
                        $token->getExpiration()->format(self::DATE_FMT)
                    );
                }
                else {
                    $str_token = '?';   // token not found
                    $can_send = false;
                }
            }
            catch (Exception $e) {
                $str_token = '/!\\';    // crash while getting token
                $can_send = false;
            }

            $output->writeln(sprintf($fmt,
                    $this->dotdot($participant->getId(), 10),
                    $this->dotdot($user->getId(), 10),
                    $this->dotdot($str_email, 30, 'left', '"', '"'),
                    $str_token
                )
            );

            if(!$can_send) {
                continue;
            }

            $url = $this->container->url('lightbox_validation', ['basket' => $basket->getId(), 'LOG' => $token->getValue()]);

            // $this->dispatch(PhraseaEvents::VALIDATION_REMINDER, new ValidationEvent($participant, $basket, $url));
            $this->doRemind($participant, $basket, $url);
        }

        $this->getEntityManager()->flush();

        return 0;
    }

    /**
     * format a string to a specified length
     *
     * @param string $s
     * @param int $l
     * @param string $align  'left' or 'right'
     * @param string $pfx       prefix to add, e.g '("'
     * @param string $sfx       suffix to add, e.g '")'
     * @return string
     */
    private function dotdot($s, $l, $align='left', $pfx='', $sfx='')
    {
        $l -= (strlen($pfx) + strlen($sfx));
        if(strlen($s) > $l) {
            $s = $pfx . substr($s, 0, $l-1) . "\xE2\x80\xA6" . $sfx;
        }
        else {
            $spc = str_repeat(' ', $l-strlen($s));
            $s = $align=='left' ? ($pfx . $s . $sfx . $spc) : ($spc . $pfx . $s . $sfx);
        }

        return $s;
    }

    private function doRemind(ValidationParticipant $participant, Basket $basket, $url)
    {
        $params = [
            'from'    => $basket->getValidation()->getInitiator()->getId(),
            'to'      => $participant->getUser()->getId(),
            'ssel_id' => $basket->getId(),
            'url'     => $url,
        ];

        $datas = json_encode($params);

        $mailed = false;

        $user_from = $basket->getValidation()->getInitiator();
        $user_to = $participant->getUser();

        if ($this->shouldSendNotificationFor($participant->getUser(), 'eventsmanager_notify_validationreminder')) {
            $readyToSend = false;
            $title = $receiver = $emitter = null;
            try {
                $title = $basket->getName();

                $receiver = Receiver::fromUser($user_to);
                $emitter = Emitter::fromUser($user_from);

                $readyToSend = true;
            }
            catch (Exception $e) {
                // no-op
            }

            if ($readyToSend) {
                $this->output->writeln(sprintf('    -> remind "<info>%s</info>" from "<info>%s</info>" to "<info>%s</info>"', $title, $receiver->getEmail(), $emitter->getEmail()));
                if(!$this->dry) {
                    // for real
                    $mail = MailInfoValidationReminder::create($this->container, $receiver, $emitter);
                    $mail->setButtonUrl($params['url']);
                    $mail->setTitle($title);

                    $this->deliver($mail);
                    $mailed = true;

                    $participant->setReminded(new DateTime('now'));
                    $this->getEntityManager()->persist($participant);
                }
            }
        }

        return $this->container['events-manager']->notify($params['to'], 'eventsmanager_notify_validationreminder', $datas, $mailed);
    }



    /**
     * @return EntityManagerInterface
     */
    private function getEntityManager()
    {
        return $this->container['orm.em'];
    }

    /**
     * @return PropertyAccess
     */
    protected function getConf()
    {
        return $this->container['conf'];
    }

    /**
     * @return ValidationParticipantRepository
     */
    private function getValidationParticipantRepository()
    {
        return $this->container['repo.validation-participants'];
    }

    /**
     * @return TokenRepository
     */
    private function getTokenRepository()
    {
        return $this->container['repo.tokens'];
    }

    /**
     * @param User $user
     * @param $type
     * @return mixed
     */
    protected function shouldSendNotificationFor(User $user, $type)
    {
        return $this->container['settings']->getUserNotificationSetting($user, $type);
    }
}
