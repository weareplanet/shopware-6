<?php declare(strict_types=1);


namespace WeArePlanetPayment\Core\Api\OrderDeliveryState\Command;

use Shopware\Core\Framework\Context;
use Symfony\Component\{
	Console\Command\Command,
    Console\Attribute\AsCommand,
	Console\Input\InputInterface,
	Console\Output\OutputInterface};
use WeArePlanetPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService;

/**
 * Class OrderDeliveryStateCommand
 *
 * @package WeArePlanetPayment\Core\Api\OrderDeliveryState\Command
 */
#[AsCommand(name: 'weareplanet:order-delivery-states:install')]
class OrderDeliveryStateCommand extends Command {

	/**
	 * @var \WeArePlanetPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService
	 */
	protected $orderDeliveryStateService;

	/**
	 * OrderDeliveryStateCommand constructor.
	 *
	 * @param \WeArePlanetPayment\Core\Api\OrderDeliveryState\Service\OrderDeliveryStateService $orderDeliveryStateService
	 */
	public function __construct(OrderDeliveryStateService $orderDeliveryStateService)
	{
		parent::__construct();
		$this->orderDeliveryStateService = $orderDeliveryStateService;
	}

	/**
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('Install WeArePlanetPayment extra delivery states...');
		$this->orderDeliveryStateService->install(Context::createDefaultContext());
		return 0;
	}

	/**
	 * Configures the current command.
	 */
	protected function configure()
	{
		$this->setDescription('Installs WeArePlanetPayment extra delivery states.')
			 ->setHelp('This command installs WeArePlanetPayment extra delivery states.');
	}

}
