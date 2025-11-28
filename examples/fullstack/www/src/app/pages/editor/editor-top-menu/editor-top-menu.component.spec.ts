import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EditorTopMenuComponent } from './editor-top-menu.component';

describe('EditorTopMenuComponent', () => {
  let component: EditorTopMenuComponent;
  let fixture: ComponentFixture<EditorTopMenuComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [EditorTopMenuComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(EditorTopMenuComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
