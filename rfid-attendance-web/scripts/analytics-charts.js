import * as echarts from 'echarts/core';
import {LineChart, ScatterChart, BarChart, HeatmapChart, CustomChart} from 'echarts/charts';
import {GridComponent, TooltipComponent, LegendComponent, VisualMapComponent, AriaComponent} from 'echarts/components';
import {SVGRenderer} from 'echarts/renderers';
echarts.use([LineChart, ScatterChart, BarChart, HeatmapChart, CustomChart, GridComponent, TooltipComponent, LegendComponent, VisualMapComponent, AriaComponent, SVGRenderer]);
window.CampusCharts = echarts;
