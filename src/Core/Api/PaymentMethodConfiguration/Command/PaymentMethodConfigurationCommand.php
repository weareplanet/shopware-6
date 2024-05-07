<?php declare(strict_types=1);


namespace WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\{
	Console\Command\Command,
    Console\Attribute\AsCommand,
	Console\Input\InputInterface,
	Console\Output\OutputInterface};
use WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService;

/**
 * Class PaymentMethodConfigurationCommand
 *
 * @package WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Command
 */
#[AsCommand(name: 'weareplanet:payment-method:configuration')]
class PaymentMethodConfigurationCommand extends Command {

	/**
	 * @var \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	protected $paymentMethodConfigurationService;

	/**
	 * PaymentMethodConfigurationCommand constructor.
	 *
	 * @param \WeArePlanetPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService $paymentMethodConfigurationService
	 */
	public function __construct(PaymentMethodConfigurationService $paymentMethodConfigurationService)
	{
		parent::__construct();
		$this->paymentMethodConfigurationService = $paymentMethodConfigurationService;
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return int
	 * @throws \WeArePlanet\Sdk\ApiException
	 * @throws \WeArePlanet\Sdk\Http\ConnectionException
	 * @throws \WeArePlanet\Sdk\VersioningException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Fetch WeArePlanetPayment space available payment methods...');
		$this->paymentMethodConfigurationService->synchronize(Context::createDefaultContext());
		return 0;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Fetches WeArePlanetPayment space available payment methods.')
			 ->setHelp('This command fetches WeArePlanetPayment space available payment methods.');
	}

}
