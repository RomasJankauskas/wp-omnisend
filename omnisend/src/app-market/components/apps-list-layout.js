import {
	Spinner,
	Flex,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalSpacer as Spacer,
} from '@wordpress/components';
import AppsList from './apps-list';
import AppsListNotice from './apps-list-notice';
import { useEffect, useState } from 'react';

const AppsListLayout = () => {
	const [apps, setApps] = useState([]);
	const [categories, setCategories] = useState([]);
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		const getApps = async () => {
			const response = await fetch(
				'https://omnisend.github.io/wp-omnisend/plugins.json'
			);

			if (!response.ok) {
				throw new Error('Failed to load apps');
			}

			return response.json();
		};

		getApps()
			.then((res) => {
				setApps(res.plugins);
				setCategories(res.categories);
				setIsLoading(false);
			})
			.catch(() => {
				// eslint-disable-next-line no-console
				console.error('Failed to load apps');
			});
	}, []);

	if (isLoading) {
		return <Spinner />;
	}

	if (!apps.length && !categories.length) {
		return <>Failed to load</>;
	}

	return (
		<Flex className="omnisend-apps-list-page-layout" justify="center">
			<div>
				<Spacer marginBottom={10}>
					<AppsListNotice />
				</Spacer>
				{categories.map((category) => (
					<div key={category.id}>
						<Spacer marginBottom={15}>
							<AppsList
								apps={apps.filter(
									(app) => app.category_id === category.id
								)}
								categoryName={category.name}
								categoryDescription={category.description}
							/>
						</Spacer>
					</div>
				))}
			</div>
		</Flex>
	);
};

export default AppsListLayout;
