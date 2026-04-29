<div alt style="text-align: center; transform: scale(.5);">
	<picture>
		<source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/surlabs/STACK/ilias10_stack/templates/assets/stack-collage.png" />
		<img alt="STACK Statistics" src="https://raw.githubusercontent.com/surlabs/STACK/ilias10_stack/templates/assets/stack-collage.png" />
	</picture>
</div>

# STACK Statistics plugin for ILIAS 10

Welcome to the official repository for the STACK Statistics plugin for ILIAS.

## What is STACK Statistics?

STACK Statistics is a companion plugin for [STACK for ILIAS](https://stackforilias.com) that adds a dedicated statistics dashboard to ILIAS tests containing `assStackQuestion` questions.

It provides filtered analytics views for teachers and students, including attempt summaries, score distributions, hint usage, PRT outcome distributions and attempt tables.

> **Note** This plugin depends on the `assStackQuestion` plugin and uses its analytics data. Install and activate `assStackQuestion` first.

## Installation & Update

### Software Requirements
STACK Statistics requires an ILIAS 10 platform.

STACK Statistics requires the `assStackQuestion` plugin for ILIAS 10 to be installed and active.

### Installation steps
1. Create subdirectories, if necessary, for `public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/`
2. In `public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/` ensure you delete any previous `assStackStatistics` folder
3. Then, execute:

```bash
git clone https://github.com/surlabs/assStackStatistics.git ./assStackStatistics
cd assStackStatistics
git checkout main
```

4. Go to `Administration > Plugins`
5. Choose action `Install` or `Update` for the `assStackStatistics` plugin
6. Choose action `Activate` for the `assStackStatistics` plugin
7. Choose action `Refresh Languages` for the `assStackStatistics` plugin

After activation, a new `STACK Statistics` tab will be available in tests that contain STACK questions.

## Usage

The plugin adds a statistics tab to ILIAS tests containing STACK questions.

Teachers can inspect aggregated data across students, including filters by question, student, hint usage, score range, attempt scope and PRT.

Students can inspect their own results and compare their score distribution against the cohort.

## Authors
* This plugin is developed and maintained by Jesús Copado, Saúl Díaz and Daniel Cazalla through [SURLABS](https://surlabs.es)

# Bug Reports & Discussion
- Bug Reports: [Mantis](https://www.ilias.de/mantis) (Choose project "ILIAS plugins" and filter by the corresponding STACK Statistics category if available)
- Repository: [GitHub](https://github.com/surlabs/assStackStatistics)

# Version History
* The stable version `10.0.x` for **ILIAS 10** can be found in the Github branch `main`
